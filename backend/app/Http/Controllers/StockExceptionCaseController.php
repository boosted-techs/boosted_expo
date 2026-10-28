<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Carton;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockExceptionCase;
use App\Models\StockLocation;
use App\Services\ReplacementOrders;
use App\Services\StockExceptionCases;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockExceptionCaseController extends Controller
{
    private function check(Request $r, string $permission = 'stock.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View|StreamedResponse
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $filters = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'in:pending,open,investigating,finance_pending,awaiting_disposition,completed,closed,rejected,withdrawn'], 'kind' => ['nullable', Rule::in(StockExceptionCases::KINDS)], 'export' => ['nullable', 'in:csv']]);
        abort_unless(empty($filters['from']) || empty($filters['to']) || $filters['from'] <= $filters['to'], 422, 'Use an observation date range in order.');
        $search = trim((string) $r->input('search'));
        $query = StockExceptionCase::query()->where('business_id', $business->id)->with('batch.product', 'carton', 'person', 'workflow')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%')->orWhereHas('batch', fn ($b) => $b->where('batch_code', 'like', '%'.$search.'%'))->orWhereHas('person', fn ($p) => $p->where('name', 'like', '%'.$search.'%'))->orWhereHas('carton', fn ($c) => $c->where('carton_code', 'like', '%'.$search.'%'))))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('event_on', '>=', $filters['from']))->when(! empty($filters['to']), fn ($q) => $q->whereDate('event_on', '<=', $filters['to']))->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->when(! empty($filters['kind']), fn ($q) => $q->where('kind', $filters['kind']));
        if ($r->input('export') === 'csv') {
            return response()->streamDownload(function () use ($query, $business, $filters) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Business', 'From', 'Through', 'Filter status', 'Filter kind', 'Filter search', 'Case', 'Kind', 'Observed', 'Status', 'Responsible', 'Follow-up due', 'Batch', 'Carton', 'Quantity', 'Unit', 'Shipment ID', 'Money source', 'Posting status', 'Reference', 'Reason'], ',', '"', '');
                foreach ((clone $query)->orderBy('id')->lazyById(100) as $case) {
                    $row = [$business->name, $filters['from'] ?? '', $filters['to'] ?? '', $filters['status'] ?? '', $filters['kind'] ?? '', $filters['search'] ?? '', $case->reference, $case->kind, $case->event_on->toDateString(), $case->status, $case->person?->name ?? 'Unassigned', $case->due_on?->toDateString(), $case->batch?->batch_code, $case->carton?->carton_code, $case->quantity, $case->batch?->product?->base_unit, $case->shipment_id, $case->workflow?->reference, $case->workflow?->posting_status, $case->external_reference, $case->reason];
                    $row = array_map(fn ($value) => preg_match('/^[=+@\-\t\r\n]/u', ltrim((string) $value)) && ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value) ? "'".$value : (string) $value, $row);
                    fputcsv($out, $row, ',', '"', '');
                }
                fclose($out);
            }, 'stock-exceptions-'.now($business->timezone)->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $cases = $query->latest('id')->workspacePaginate(25, ['*'], 'case_page')->withQueryString();
        $batches = StockBatch::query()->where('business_id', $business->id)->with('product')->when($search !== '', fn ($q) => $q->where('batch_code', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(40, ['*'], 'batch_page')->withQueryString();
        $cartons = Carton::query()->where('business_id', $business->id)->whereIn('status', ['packed', 'allocated', 'shipped'])->with('contents.batch', 'shipmentAllocation.shipment')->when($search !== '', fn ($q) => $q->where('carton_code', 'like', '%'.$search.'%'))->latest('id')->workspacePaginate(40, ['*'], 'carton_page')->withQueryString();
        $locations = StockLocation::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get();
        $people = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->workspacePaginate(35, ['*'], 'people_page')->withQueryString();
        $replacements = SalesOrder::query()->where('business_id', $business->id)->whereIn('replacement_case_id', $cases->modelKeys())->get()->groupBy('replacement_case_id');
        $events = DB::table('stock_exception_events')->where('business_id', $business->id)->whereIn('case_id', $cases->modelKeys())->orderBy('id')->get()->groupBy('case_id');
        $exposure = $cases->mapWithKeys(fn ($case) => [$case->id => $case->batch_id ? app(StockExceptionCases::class)->affected($business, $case->batch_id)['cartons'] : ($case->data_snapshot['source']['cartons'] ?? [])]);
        $evidence = DB::table('stock_case_evidence')->where('business_id', $business->id)->whereIn('case_id', $cases->modelKeys())->orderBy('id')->get()->groupBy('case_id');
        $access = app(BusinessPermissions::class);
        $member = $r->attributes->get('membership');
        $canRecord = $access->allows($member, 'stock.adjust.record');
        $canReview = $access->allows($member, 'stock.adjust.review');
        $canPack = $access->allows($member, 'packing.manage');

        return view('stock.cases', compact('business', 'cases', 'batches', 'cartons', 'locations', 'people', 'events', 'canRecord', 'canReview', 'canPack', 'replacements', 'evidence', 'exposure'));
    }

    public function store(Request $r, StockExceptionCases $cases): RedirectResponse
    {
        $this->check($r, $r->input('kind') === 'partial_repack' ? 'packing.manage' : 'stock.adjust.record');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['kind' => ['required', Rule::in(StockExceptionCases::KINDS)], 'due_on' => ['nullable', 'date_format:Y-m-d'], 'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($b->timezone)->toDateString()], 'batch_id' => ['required_unless:kind,partial_repack', 'nullable', 'integer', 'min:1'], 'carton_id' => ['required_if:kind,partial_repack,customer_return', 'nullable', 'integer', 'min:1'], 'quantity' => ['required_if:kind,location_split,customer_return,supplier_return', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'to_location_id' => ['required_if:kind,location_split,customer_return', 'nullable', 'integer', 'min:1'], 'staff_person_id' => ['nullable', 'integer', 'min:1'], 'release' => ['required_if:kind,partial_repack', 'nullable', 'array', 'max:100'], 'release.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'], 'net_weight_kg' => ['required_if:kind,partial_repack', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'gross_weight_kg' => ['required_if:kind,partial_repack', 'nullable', 'numeric', 'gte:net_weight_kg', 'decimal:0,3', 'max:999999999'], 'external_reference' => ['required', 'string', 'min:3', 'max:300'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $cases->prepare($b, $data['kind'], $data, $r->user()->id);

        return back()->with('status', 'Exception recorded with original source evidence. Quantity and financial outcomes remain subject to their reviews.');
    }

    public function review(Request $r, int $case, string $decision, StockExceptionCases $cases): RedirectResponse
    {
        $this->check($r, 'stock.adjust.review');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $cases->review($b, StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($case), $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Physical exception reviewed. Returned-goods quantity and cost need the linked financial posting before completion.');
    }

    public function replacement(Request $r, int $case, ReplacementOrders $replacements): RedirectResponse
    {
        $this->check($r, 'stock.adjust.review');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['shipment_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'required_on' => ['required', 'date_format:Y-m-d'], 'external_reference' => ['required', 'string', 'min:3', 'max:200'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $order = $replacements->create($b, StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($case), $data, $r->user()->id);

        return redirect()->route('sales-orders.show', $order->id)->with('status', 'Replacement authorised. Source, pack and deliver it through the order workspace.');
    }

    public function uploadEvidence(Request $r, int $case, StockExceptionCases $cases): RedirectResponse
    {
        $this->check($r, 'stock.adjust.record');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], 'note' => ['required', 'string', 'min:20', 'max:3000']]);
        $file = $r->file('file');
        $path = null;
        try {
            DB::transaction(function () use ($b, $case, $data, $file, $r, $cases, &$path) {
                Business::query()->whereKey($b->id)->lockForUpdate()->firstOrFail();
                $record = StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($case);
                $cases->verify($record);
                $sha = hash_file('sha256', $file->getRealPath());
                abort_if(DB::table('stock_case_evidence')->where('business_id', $b->id)->where('case_id', $record->id)->where('sha256', $sha)->exists(), 422, 'This original evidence is already attached to the case.');
                $path = $file->store('stock-case-evidence/'.$b->id, 'local');
                DB::table('stock_case_evidence')->insert(['business_id' => $b->id, 'case_id' => $record->id, 'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'sha256' => $sha, 'note' => $data['note'], 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $cases->event($record, 'evidence_attached', $data['note'], $r->user()->id);
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return back()->with('status', 'Original case evidence retained privately with its fingerprint and history.');
    }

    public function downloadEvidence(Request $r, int $evidence): BinaryFileResponse
    {
        $this->check($r);
        $b = $r->attributes->get('activeBusiness');
        $file = DB::table('stock_case_evidence')->where('business_id', $b->id)->find($evidence);
        abort_unless($file, 404);
        StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($file->case_id);
        $disk = Storage::disk('local');
        abort_unless(str_starts_with($file->path, 'stock-case-evidence/'.$b->id.'/') && $disk->exists($file->path) && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path))), 422, 'The original evidence is missing or changed. Record a new attachment and investigate the original.');

        return response()->download($disk->path($file->path), $file->name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function progress(Request $r, int $case, StockExceptionCases $cases): RedirectResponse
    {
        $this->check($r, $r->input('action') === 'withdraw' ? 'stock.adjust.record' : 'stock.adjust.review');
        $data = $r->validate(['due_on' => ['nullable', 'date_format:Y-m-d'], 'action' => ['required', 'in:withdraw,investigate,contact_recorded,scope_excluded,disposition_recorded,close,reopen'], 'shipment_id' => ['nullable', 'integer', 'min:1'], 'staff_person_id' => ['nullable', 'integer', 'min:1'], 'disposition' => ['nullable', 'in:restock,writeoff,replacement'], 'note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $cases->progress($b, StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($case), $data, $r->user()->id);

        return back()->with('status', 'Case evidence updated. Recorded contact evidence does not send a message.');
    }
}
