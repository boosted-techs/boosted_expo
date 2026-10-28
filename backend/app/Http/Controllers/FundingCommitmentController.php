<?php

namespace App\Http\Controllers;

use App\Models\FinanceOperation;
use App\Models\FinancePayrollRun;
use App\Models\FinanceRegisterEntry;
use App\Models\FundingCommitment;
use App\Services\FinanceFunding;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FundingCommitmentController extends Controller
{
    private function check(Request $r, string $permission = 'finance.reports.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r, FinanceFunding $funding): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120'], 'collection_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2']]);
        $search = trim((string) $r->input('search'));
        $collectionPercent = $r->input('collection_percent', '100');
        $report = $funding->report($business, $collectionPercent);
        $plans = FundingCommitment::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('purpose', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(25, ['*'], 'plan_page')->withQueryString();
        $remaining = $plans->mapWithKeys(fn ($plan) => [$plan->id => $funding->remaining($business, $plan)]);
        $sources = [];
        foreach (['expense' => FinanceOperation::class, 'payroll' => FinancePayrollRun::class, 'asset' => FinanceRegisterEntry::class] as $type => $model) {
            $query = $model::query()->where('business_id', $business->id)->where('posting_status', 'posted')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'));
            if ($type === 'expense') {
                $query->whereIn('kind', ['expense', 'paid_expense', 'staff_expense', 'market_reimbursement']);
            }if ($type === 'asset') {
                $query->where('kind', 'asset_purchase');
            }$sources[$type] = $query->latest('id')->workspacePaginate(30, ['*'], $type.'_page')->withQueryString();
        }
        $access = app(BusinessPermissions::class);
        $membership = $r->attributes->get('membership');
        $canCreate = $access->allows($membership, 'finance.documents.create');
        $canReview = $access->allows($membership, 'finance.reviews.approve');

        return view('finance.funding', compact('business', 'report', 'collectionPercent', 'plans', 'remaining', 'sources', 'canCreate', 'canReview'));
    }

    public function store(Request $r, FinanceFunding $funding): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['kind' => ['required', 'in:wages,tax,equipment,operating'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($b)], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'due_on' => ['required', 'date_format:Y-m-d'], 'purpose' => ['required', 'string', 'max:150'], 'evidence' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $funding->propose($b, $data, $r->user()->id);

        return back()->with('status', 'Expected commitment proposed for independent review. No expense, debt or payment was posted.');
    }

    public function review(Request $r, int $plan, string $decision, FinanceFunding $funding): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $row = FundingCommitment::query()->where('business_id', $b->id)->findOrFail($plan);
        if ($decision === 'cancel') {
            $funding->cancel($b, $row, $data['note'], $r->user()->id);
        } else {
            $funding->review($b, $row, $decision === 'approve', $data['note'], $r->user()->id);
        }

        return back()->with('status', 'Funding commitment reviewed with history retained.');
    }

    public function recognise(Request $r, int $plan, FinanceFunding $funding): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        if (is_string($r->input('source_choice')) && preg_match('/^(expense|payroll|asset):([1-9][0-9]*)$/D', $r->input('source_choice'), $choice)) {
            $r->merge(['source_type' => $choice[1], 'source_id' => (int) $choice[2]]);
        }
        $data = $r->validate(['source_type' => ['required', 'in:expense,payroll,asset'], 'source_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $funding->recognise($b, FundingCommitment::query()->where('business_id', $b->id)->findOrFail($plan), $data, $r->user()->id);

        return back()->with('status', 'Actual posted source linked. Its live payable replaces this portion of the forecast.');
    }
}
