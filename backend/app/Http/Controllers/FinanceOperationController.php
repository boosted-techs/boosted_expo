<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinanceOperationRequest;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\MarketTrip;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Services\FinanceDocuments;
use App\Services\FinanceOperations;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceOperationController extends Controller
{
    private function check(Request $request): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.documents.view'), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request);
        $business = $request->attributes->get('activeBusiness');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', Rule::in(array_keys(FinanceOperations::KINDS))]]);
        $operations = FinanceOperation::query()->where('business_id', $business->id)
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%')->orWhere('external_reference', 'like', '%'.$term.'%')))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->latest('id')->paginate(25)->withQueryString();
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.documents.create');

        return view('finance.money.index', compact('business', 'operations', 'canCreate'));
    }

    public function create(Request $request): View
    {
        $this->check($request);
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.documents.create'), 403);
        $request->validate(['kind' => ['required', Rule::in(array_keys(FinanceOperations::KINDS))], 'search' => ['nullable', 'string', 'max:120']]);
        $business = $request->attributes->get('activeBusiness');
        $kind = $request->input('kind');
        $search = trim((string) $request->input('search'));
        $lookups = [];
        foreach (['customer_id' => Customer::class, 'supplier_id' => Supplier::class, 'staff_person_id' => StaffPerson::class,
            'sales_order_id' => SalesOrder::class, 'market_trip_id' => MarketTrip::class, 'shipment_id' => ExportShipment::class] as $key => $model) {
            $column = in_array($key, ['customer_id', 'supplier_id', 'staff_person_id'], true) ? 'name' : 'reference';
            $lookups[$key] = $model::query()->where('business_id', $business->id)
                ->when($search !== '', fn ($q) => $q->where($column, 'like', '%'.$search.'%'))->orderByDesc('id')->limit(50)->get();
        }
        $parentKind = match ($kind) {
            'customer_apply','customer_refund' => 'customer_deposit','supplier_apply','supplier_return' => 'supplier_advance',
            'staff_apply','staff_return','staff_expense' => 'staff_advance','expense_payment' => 'expense',default => null,
        };
        $parents = FinanceOperation::query()->where('business_id', $business->id)->where('posting_status', 'posted')->where('kind', $parentKind)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')))->latest('id')->paginate(20, ['*'], 'sources')->withQueryString();
        $balances = $parents->mapWithKeys(fn ($p) => [$p->id => (string) app(FinanceOperations::class)->remaining($p)]);
        $invoices = app(FinanceDocuments::class)->outstandingQuery($business, true)->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->paginate(20, ['*'], 'invoices')->withQueryString();
        $bills = app(FinanceDocuments::class)->outstandingQuery($business, false)->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->paginate(20, ['*'], 'bills')->withQueryString();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->orderByDesc('effective_on')->get();
        $bankProfiles = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->orderBy('label')->get();
        $submissionKey = (string) Str::uuid();

        return view('finance.money.create', compact('business', 'kind', 'lookups', 'parents', 'balances', 'invoices', 'bills', 'rates', 'bankProfiles', 'submissionKey'));
    }

    public function store(StoreFinanceOperationRequest $request, FinanceOperations $operations): RedirectResponse
    {
        $operation = $operations->prepare($request->attributes->get('activeBusiness'), $request->validated(), $request->user()->id);

        return redirect()->route('finance.money.show', $operation->id)->with('status', 'Money record prepared. It needs independent review and posting.');
    }

    public function upload(Request $request, int $operation, FinanceOperations $operations): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.documents.create'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $business = $request->attributes->get('activeBusiness');
        $record = FinanceOperation::query()->where('business_id', $business->id)->findOrFail($operation);
        $operations->attachEvidence($business, $record, $request->file('file'), $request->user()->id);

        return back()->with('status', 'Private supporting evidence attached.');
    }

    public function evidence(Request $request, int $operation, int $file): StreamedResponse
    {
        $this->check($request);
        $business = $request->attributes->get('activeBusiness');
        $operation = FinanceOperation::query()->where('business_id', $business->id)->findOrFail($operation);
        $item = $operation->data_snapshot['evidence'][$file] ?? null;
        abort_unless($item && str_starts_with($item['path'], 'finance-evidence/'.$business->id.'/'), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($item['path']) && hash_equals($item['sha256'], hash_file('sha256', $disk->path($item['path']))), 404);

        return $disk->download($item['path'], 'money-evidence-'.$operation->id.'-'.$file.'.'.pathinfo($item['path'], PATHINFO_EXTENSION), ['Content-Type' => $item['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(Request $request, int $operation): View
    {
        $this->check($request);
        $business = $request->attributes->get('activeBusiness');
        $operation = FinanceOperation::query()->where('business_id', $business->id)->findOrFail($operation);
        $children = FinanceOperation::query()->where('business_id', $business->id)->where('parent_id', $operation->id)->latest('id')->paginate(25);
        $balance = app(FinanceOperations::class)->remaining($operation);
        $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'money_operation')->where('source_id', $operation->id)->first();
        $review = FinancePostingRequest::query()->where('business_id',$business->id)->where('source_type','money_operation')->where('source_id',$operation->id)->first();
        $canSubmit = app(BusinessPermissions::class)->allows($request->attributes->get('membership'),'finance.reviews.request');

        return view('finance.money.show',compact('business','operation','children','balance','journal','review','canSubmit'));
    }
}
