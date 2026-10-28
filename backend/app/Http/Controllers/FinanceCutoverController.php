<?php

namespace App\Http\Controllers;

use App\Models\CashCustody;
use App\Models\CommercialInvoice;
use App\Models\FinanceAccount;
use App\Models\FinanceBankProfile;
use App\Models\FinanceCutover;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceOpeningItem;
use App\Models\FinanceOperation;
use App\Models\FinanceSupplierBill;
use App\Services\FinanceCutovers;
use App\Services\FinanceOpeningItems;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceCutoverController extends Controller
{
    private function check(Request $request, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request, FinanceOpeningItems $openings): View
    {
        $this->check($request);
        $business = $request->attributes->get('activeBusiness');
        $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $request->input('search'));
        $cutovers = FinanceCutover::query()->where('business_id', $business->id)->latest('id')->workspacePaginate(10, ['*'], 'cutover_page')->withQueryString();
        $items = FinanceOpeningItem::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->orderBy('due_on')->orderBy('id')->workspacePaginate(30, ['*'], 'item_page')->withQueryString();
        $states = $items->mapWithKeys(fn ($item) => [$item->id => $openings->position($item, true)]);
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->orderBy('label')->get();
        $cash = CashCustody::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->orderByDesc('effective_on')->get();
        $accounts = FinanceAccount::query()->where('business_id', $business->id)->where('active', true)->whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();
        $targets = [];
        foreach ([['supplier_bill', FinanceSupplierBill::class], ['commercial_invoice', CommercialInvoice::class], ['expense', FinanceOperation::class]] as [$type, $model]) {
            $query = $model::query()->where('business_id', $business->id)->where('posting_status', 'posted')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'));
            if ($type === 'expense') {
                $query->whereIn('kind', ['expense', 'market_reimbursement'])->whereNotNull('staff_person_id');
            }
            $targets[$type] = $query->latest('id')->workspacePaginate(25, ['*'], $type.'_page')->withQueryString();
        }
        $access = app(BusinessPermissions::class);
        $member = $request->attributes->get('membership');
        $canCreate = $access->allows($member, 'finance.documents.create');
        $canAccept = $access->allows($member, 'finance.cutover.accept');

        return view('finance.cutover', compact('business', 'cutovers', 'items', 'states', 'banks', 'cash', 'rates', 'accounts', 'targets', 'canCreate', 'canAccept'));
    }

    public function template(Request $request): StreamedResponse
    {
        $this->check($request, 'finance.documents.create');

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, FinanceCutovers::COLUMNS, ',', '"', '');
            fclose($out);
        }, 'exporter-opening-data.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, FinanceCutovers $cutovers): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $b = $request->attributes->get('activeBusiness');
        $data = $request->validate(['effective_on' => ['required', 'date_format:Y-m-d'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $file = $request->file('file');
        unset($data['file']);
        $data['file_sha256'] = hash_file('sha256', $file->getRealPath());
        $rows = $cutovers->readCsv($file->getRealPath());
        $cutover = $cutovers->prepare($b, $data, $rows, $request->user()->id);
        if ($cutover->wasRecentlyCreated) {
            $path = $file->store('cutover-evidence/'.$b->id, 'local');
            $cutover->update(['source_file_path' => $path, 'source_file_sha256' => $data['file_sha256']]);
        }

        return $cutover->workflow_id ? redirect()->route('finance.treasury.show', $cutover->workflow_id)->with('status', 'Opening import reconciles to its declared controls and is ready for independent posting review.') : redirect()->route('finance.cutover.index')->with('status', 'Existing openings reconciled. A separate owner must verify and accept the cutover.');
    }

    public function accept(Request $request, int $cutover, FinanceCutovers $cutovers): RedirectResponse
    {
        $this->check($request, 'finance.cutover.accept');
        $data = $request->validate(['note' => ['required', 'string', 'min:30', 'max:3000']]);
        $b = $request->attributes->get('activeBusiness');
        $cutovers->accept($b, FinanceCutover::query()->where('business_id', $b->id)->findOrFail($cutover), $data['note'], $request->user()->id);

        return back()->with('status', 'Opening stock, debts, cash and register controls accepted. Earlier financial history is now locked.');
    }

    public function settle(Request $request, int $item, FinanceOpeningItems $openings): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $b = $request->attributes->get('activeBusiness');
        if ($request->filled('money_source')) {
            $parts = explode(':', (string) $request->input('money_source'));
            abort_unless(count($parts) === 2 && in_array($parts[0], ['bank', 'cash', 'legacy'], true), 422);
            $request->merge([$parts[0] === 'bank' ? 'bank_profile_id' : ($parts[0] === 'cash' ? 'cash_custody_id' : 'account_key') => $parts[1]]);
        }
        if ($request->filled('target')) {
            $parts = explode(':', (string) $request->input('target'));
            abort_unless(count($parts) === 2, 422);
            $request->merge(['target_type' => $parts[0], 'target_id' => $parts[1]]);
        }
        $data = $request->validate(['kind' => ['required', 'in:opening_item_settlement,opening_item_apply,opening_item_adjustment'], 'event_on' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'bank_profile_id' => ['nullable', 'integer', 'min:1'], 'cash_custody_id' => ['nullable', 'integer', 'min:1'], 'account_key' => ['nullable', 'in:cash,bank'], 'target_type' => ['required_if:kind,opening_item_apply', 'nullable', 'in:supplier_bill,commercial_invoice,expense'], 'target_id' => ['required_if:kind,opening_item_apply', 'nullable', 'integer', 'min:1'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $flow = $openings->prepare($b, FinanceOpeningItem::query()->where('business_id', $b->id)->findOrFail($item), $data['kind'], $data, $request->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Opening source settlement/application prepared for independent review.');
    }

    public function revalue(Request $request, int $item, FinanceOpeningItems $openings): RedirectResponse
    {
        $this->check($request, 'finance.documents.create');
        $b = $request->attributes->get('activeBusiness');
        $data = $request->validate(['event_on' => ['required', 'date_format:Y-m-d'], 'exchange_rate_id' => ['required', 'integer', 'min:1'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $flow = $openings->revalue($b, FinanceOpeningItem::query()->where('business_id', $b->id)->findOrFail($item), $data, $request->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Foreign opening valuation prepared for independent review. Original currency units remain unchanged.');
    }

    public function evidence(Request $request, int $cutover): StreamedResponse
    {
        $this->check($request);
        $b = $request->attributes->get('activeBusiness');
        $row = FinanceCutover::query()->where('business_id', $b->id)->findOrFail($cutover);
        $disk = Storage::disk('local');
        abort_unless($row->source_file_path && str_starts_with($row->source_file_path, 'cutover-evidence/'.$b->id.'/') && $disk->exists($row->source_file_path) && hash_equals($row->source_file_sha256, hash_file('sha256', $disk->path($row->source_file_path))), 404);

        return $disk->download($row->source_file_path, $row->reference.'.csv', ['Cache-Control' => 'private, no-store']);
    }
}
