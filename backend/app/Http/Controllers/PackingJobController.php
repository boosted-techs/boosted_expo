<?php

namespace App\Http\Controllers;

use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockReservation;
use App\Services\OrderSourcing;
use App\Services\PackingOperations;
use App\Services\StockAvailability;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackingJobController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission
        ), 403);
    }

    private function own(Request $request, int $job): PackingJob
    {
        return PackingJob::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($job);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'packing.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $jobs = PackingJob::query()->where('business_id', $businessId)
            ->with('product')->withCount(['cartons', 'losses'])->latest()->paginate(20);
        $products = Product::query()->where('business_id', $businessId)
            ->where('active', true)->orderBy('name')->get();
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'packing.manage'
        );

        return view('packing.index', compact('jobs', 'products', 'canManage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'packing.manage');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'planned_on' => ['nullable', 'date'],
            'target_cartons' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'packing_type' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $businessId = $request->attributes->get('activeBusiness')->id;
        Product::query()->where('business_id', $businessId)->where('active', true)
            ->findOrFail($data['product_id']);
        $job = PackingJob::create([
            ...$data,
            'business_id' => $businessId,
            'reference' => 'PK-'.Str::upper((string) Str::ulid()),
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('packing.show', $job->id)->with('status', 'Packing job created.');
    }

    public function show(Request $request, int $job, StockAvailability $inventory): View
    {
        $this->check($request, 'packing.view');
        $job = $this->own($request, $job)->load([
            'product', 'cartons.contents.batch', 'losses.batch',
        ]);
        $batches = StockBatch::query()->where('business_id', $job->business_id)
            ->where('product_id', $job->product_id)->orderBy('id')->get()->map(function ($batch) use ($job) {
                $source = app(OrderSourcing::class);
                $available = $source->free($batch, $job->sales_order_line_id);
                if ($job->sales_order_line_id) {
                    $own = BigDecimal::zero();
                    foreach (StockReservation::query()->where('business_id', $job->business_id)->where('sales_order_line_id', $job->sales_order_line_id)->where('batch_id', $batch->id)->whereNull('released_at')->get() as $reservation) {
                        $own = $own->plus((string) $reservation->quantity)->minus((string) $reservation->consumed_quantity)->minus((string) $reservation->lost_quantity);
                    }$available = BigDecimal::min($available, $own);
                }

                return ['batch' => $batch, 'available' => (string) $available];
            });
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'packing.manage'
        );
        $templates = LabelTemplate::query()->where('business_id', $job->business_id)
            ->where('active', true)->orderBy('name')->get();
        $cartonKey = (string) Str::uuid();
        $wasteKey = (string) Str::uuid();

        return view('packing.show', compact(
            'job', 'batches', 'canManage', 'templates', 'cartonKey', 'wasteKey'
        ));
    }

    public function pack(Request $request, int $job, PackingOperations $packing): RedirectResponse
    {
        $this->check($request, 'packing.manage');
        $data = $request->validate([
            'packing_key' => ['required', 'uuid'],
            'contents' => ['required', 'array', 'min:1', 'max:10'],
            'contents.*.batch_id' => ['required', 'integer', 'distinct'],
            'contents.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'net_weight_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'gross_weight_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'grade' => ['nullable', 'string', 'max:50'],
            'packaging_type' => ['nullable', 'string', 'max:60'],
        ]);
        $job = $this->own($request, $job);
        $carton = $packing->packCarton($job, $data, $request->user()->id);

        return redirect()->route('cartons.show', $carton->id)->with('status',
            'Carton packed. Its batch quantities have been deducted from unpacked stock.'
        );
    }

    public function loss(Request $request, int $job, PackingOperations $packing): RedirectResponse
    {
        $this->check($request, 'packing.manage');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'batch_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'reason' => ['required', Rule::in(['sorting_reject', 'spoiled', 'damaged', 'trim_loss', 'other'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $job = $this->own($request, $job);
        $packing->recordWaste($job, $data, $request->user()->id);

        return redirect()->route('packing.show', $job->id)->with('status',
            'Sorting loss recorded and deducted from available stock.'
        );
    }

    public function complete(Request $request, int $job, PackingOperations $packing): RedirectResponse
    {
        $this->check($request, 'packing.manage');
        $job = $this->own($request, $job);
        $packing->complete($job, $request->user()->id);

        return redirect()->route('packing.show', $job->id)->with('status',
            'Packing job completed. Its stock and carton records are now locked.'
        );
    }
}
