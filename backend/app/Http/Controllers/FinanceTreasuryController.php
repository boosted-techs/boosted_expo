<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTreasuryWorkflowRequest;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\RecurringObligation;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Services\FinanceTreasury;
use App\Services\FinanceWorkflowCorrections;
use App\Services\FinanceWorkflows;
use App\Services\OrderOperations;
use App\Services\PlatformAccess;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceTreasuryController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $r->input('search'));
        $workflows = FinanceWorkflow::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('external_reference', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%')))->latest('id')->workspacePaginate(25, ['*'], 'workflow_page')->withQueryString();
        $custodies = CashCustody::query()->where('business_id', $business->id)->with('person')->orderBy('name')->get();
        $cashStates = $custodies->mapWithKeys(fn ($c) => [$c->id => app(FinanceWorkflows::class)->accountBasis($business->id, $c->account_id, now($business->timezone)->toDateString())]);
        $counts = CashClose::query()->where('business_id', $business->id)->latest('id')->workspacePaginate(20, ['*'], 'count_page')->withQueryString();
        $obligations = RecurringObligation::query()->where('business_id', $business->id)->orderBy('next_due_on')->workspacePaginate(25, ['*'], 'recurring_page')->withQueryString();
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->orderBy('label')->get();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->orderByDesc('effective_on')->get();
        $people = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->workspacePaginate(40, ['*'], 'people_page')->withQueryString();
        $suppliers = Supplier::query()->where('business_id', $business->id)->where('active', true)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->workspacePaginate(40, ['*'], 'supplier_page')->withQueryString();
        $canCreate = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $canReview = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.approve');

        return view('finance.treasury.index', compact('business', 'workflows', 'custodies', 'cashStates', 'counts', 'obligations', 'banks', 'rates', 'people', 'suppliers', 'canCreate', 'canReview'));
    }

    public function store(StoreTreasuryWorkflowRequest $r, FinanceTreasury $treasury): RedirectResponse
    {
        $data = $r->validated();
        $workflow = $treasury->prepare($r->attributes->get('activeBusiness'), $data['kind'], $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $workflow->id)->with('status', 'Money event prepared. Attach its evidence and send it for independent review.');
    }

    public function show(Request $r, int $workflow): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $workflow = FinanceWorkflow::query()->where('business_id', $business->id)->with('legs')->findOrFail($workflow);
        $review = FinancePostingRequest::query()->where('business_id', $business->id)->where('source_type', 'finance_workflow')->where('source_id', $workflow->id)->first();
        $journals = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'workflow_leg')->whereIn('source_id', $workflow->legs->modelKeys())->get();
        $canCreate = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $canSubmit = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.request');

        return view('finance.treasury.show', compact('business', 'workflow', 'review', 'journals', 'canCreate', 'canSubmit'));
    }

    public function correction(Request $r, int $workflow, FinanceWorkflowCorrections $corrections): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['event_on' => ['required', 'date_format:Y-m-d'], 'external_reference' => ['required', 'string', 'min:3', 'max:200'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid'], 'cash_witness' => ['nullable', 'string', 'min:10', 'max:1000'], 'return_bank_lines' => ['nullable', 'array', 'max:100'], 'return_bank_lines.*' => ['nullable', 'integer', 'min:1']]);
        $flow = $corrections->prepare($b, FinanceWorkflow::query()->where('business_id', $b->id)->findOrFail($workflow), $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Complete correction prepared for independent review. Original evidence remains available.');
    }

    public function cancel(Request $r, int $workflow, FinanceWorkflows $flows): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $row = FinanceWorkflow::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($workflow);
        $flows->cancel($row, $r->user()->id, $data['reason']);

        return back()->with('status', 'Unsubmitted event withdrawn with history retained.');
    }

    public function upload(Request $r, int $workflow, FinanceWorkflows $flows): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $r->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $row = FinanceWorkflow::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($workflow);
        $flows->attach($row, $r->file('file'), $r->user()->id);

        return back()->with('status', 'Original evidence attached privately.');
    }

    public function evidence(Request $r, int $workflow, int $file): StreamedResponse
    {
        $this->check($r);
        $row = FinanceWorkflow::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($workflow);
        $item = $row->data_snapshot['evidence'][$file] ?? null;
        $disk = Storage::disk('local');
        abort_unless($item && str_starts_with($item['path'], 'workflow-evidence/'.$row->business_id.'/') && $disk->exists($item['path']) && hash_equals($item['sha256'], hash_file('sha256', $disk->path($item['path']))), 404);

        return $disk->download($item['path'], 'finance-evidence-'.$row->id.'-'.$file.'.'.pathinfo($item['path'], PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'Content-Type' => $item['mime'], 'X-Content-Type-Options' => 'nosniff']);
    }

    public function custody(Request $r, FinanceTreasury $treasury): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['use_general_cash' => ['nullable', 'boolean'], 'name' => ['required', 'string', 'min:3', 'max:120'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'staff_person_id' => ['required', 'integer', 'min:1'], 'authorised_float' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999']]);
        $treasury->createCustody($business, $data, $r->user()->id);

        return back()->with('status', 'Accountable cash float created. A top-up records the actual money handed over.');
    }

    public function count(Request $r, int $custody, FinanceTreasury $treasury): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $row = CashCustody::query()->where('business_id', $business->id)->findOrFail($custody);
        $data = $r->validate(['exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'counted' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'denominations' => ['nullable', 'array', 'max:50'], 'denominations.*.value' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999'], 'denominations.*.count' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $treasury->count($business, $row, $data, $r->user()->id);

        return back()->with('status', 'Actual cash count captured. A separate reviewer checks it before the day is closed.');
    }

    public function reviewCount(Request $r, int $count, string $decision, FinanceTreasury $treasury): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $row = CashClose::query()->where('business_id', $business->id)->findOrFail($count);
        $treasury->reviewCount($business, $row, $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Cash count reviewed. A variance needs its financial posting before closure.');
    }

    public function reopenCount(Request $r, int $count): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        DB::transaction(function () use ($r, $count, $data, $business) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $row = CashClose::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($count);
            abort_unless($row->status === 'closed', 422, 'Only a closed cash count can be reopened.');
            $row->update(['status' => 'reopened']);
            app(PlatformAccess::class)->audit($business->id, $r->user()->id, 'cash.close_reopened', $data['reason'], [], ['cash_close_id' => $row->id]);
        }, 3);

        return back()->with('status', 'Cash close reopened with history retained. Correct the vouchers, then count and review again.');
    }

    public function recurring(Request $r, FinanceTreasury $treasury): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['name' => ['required', 'string', 'min:3', 'max:150'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'category' => ['required', 'in:rent,utilities,packing_material,transport,handling,repairs,communication,professional_fees,staff_welfare,other'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'staff_person_id' => ['nullable', 'integer', 'min:1'], 'next_due_on' => ['required', 'date_format:Y-m-d'], 'interval_months' => ['required', 'integer', 'between:1,120'], 'terms' => ['required', 'string', 'min:20', 'max:3000']]);
        $treasury->recurring($business, $data, $r->user()->id);

        return back()->with('status', 'Recurring obligation recorded. Due dates suggest work; no expense or payment is created automatically.');
    }

    public function recognise(Request $r, int $obligation, FinanceTreasury $treasury): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $row = RecurringObligation::query()->where('business_id', $business->id)->findOrFail($obligation);
        $data = $r->validate(['due_on' => ['required', 'date_format:Y-m-d'], 'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'request_key' => ['required', 'uuid']]);
        $expense = $treasury->recognise($business, $row, $data, $r->user()->id);

        return redirect()->route('finance.money.show', $expense->id)->with('status', 'Due occurrence prepared as an unpaid expense for review. No payment was made.');
    }

    public function recurringState(Request $r, int $obligation): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $data = $r->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        DB::transaction(function () use ($r, $obligation, $data, $business) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $row = RecurringObligation::query()->where('business_id', $business->id)->findOrFail($obligation);
            $row->update(['active' => $data['active']]);
            app(PlatformAccess::class)->audit($business->id, $r->user()->id, 'finance.recurring_status', $data['reason'], [], ['obligation_id' => $row->id, 'active' => $row->active]);
        }, 3);

        return back()->with('status', 'Recurring reminder status updated; existing expenses remain unchanged.');
    }
}
