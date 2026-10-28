<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\GoodsReceiving;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'receipts.view');
        $business = $request->attributes->get('activeBusiness');
        $receipts = GoodsReceipt::query()->where('business_id', $business->id)
            ->with(['purchaseOrder.product', 'batch'])->latest()->workspacePaginate(20);
        $canReceive = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'receipts.post');

        return view('receiving.index', compact('receipts', 'canReceive'));
    }

    public function create(Request $request, int $purchase): View
    {
        $this->check($request, 'receipts.post');
        $business = $request->attributes->get('activeBusiness');
        $purchase = PurchaseOrder::query()->where('business_id', $business->id)
            ->with(['product', 'receipts'])->findOrFail($purchase);
        abort_unless(in_array($purchase->status, ['ordered', 'part_received'], true), 422,
            'This purchase is no longer open for receiving.');
        $formKey = (string) Str::uuid();

        return view('receiving.create', compact('purchase', 'formKey'));
    }

    public function store(Request $request, int $purchase, GoodsReceiving $receiving): RedirectResponse
    {
        $this->check($request, 'receipts.post');
        $data = $request->validate([
            'accepted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'],
            'rejected_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'],
            'tare_weight_kg' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'],
            'farmer_code' => ['nullable', 'string', 'max:120'],
            'quality_notes' => ['nullable', 'string', 'max:2000'],
            'received_on' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $purchase = PurchaseOrder::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->with('product')->findOrFail($purchase);
        $receipt = $receiving->post($purchase, $data, $request->user()->id);

        return redirect()->route('purchases.show', $purchase->id)
            ->with('status', 'Goods receipt '.$receipt->reference.' posted and stock updated.');
    }

    public function stock(Request $request): View
    {
        $this->check($request, 'receipts.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $request->validate(['search' => ['nullable', 'string', 'max:120'], 'quality' => ['nullable', 'in:held,cleared']]);
        $heldCount = StockBatch::query()->where('business_id', $businessId)->where('is_quarantined', true)->count();
        $batches = StockBatch::query()->where('business_id', $businessId)->with('product')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('batch_code', 'like', '%'.$request->input('search').'%')->orWhere('source_reference', 'like', '%'.$request->input('search').'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$request->input('search').'%'))))
            ->when($request->filled('quality'), fn ($q) => $q->where('is_quarantined', $request->input('quality') === 'held'))
            ->orderByDesc('id')->workspacePaginate(25);
        // Read just the batches on this page and sum signed movements as precise decimals.
        $batchIds = $batches->getCollection()->pluck('id');
        $movements = StockMovement::query()->where('business_id', $businessId)
            ->whereIn('batch_id', $batchIds)->get(['batch_id', 'quantity']);
        $balanceByBatch = [];
        foreach ($movements as $movement) {
            $previous = $balanceByBatch[$movement->batch_id] ?? BigDecimal::zero();
            $balanceByBatch[$movement->batch_id] =
                $previous->plus((string) $movement->quantity);
        }

        $publicByBatch = QrIdentity::query()->where('business_id', $businessId)
            ->where('level', 'batch')->where('public_enabled', true)
            ->whereIn('record_id', $batchIds)
            ->pluck('record_id')->mapWithKeys(fn ($id) => [$id => true]);
        $access = app(BusinessPermissions::class);
        $membership = $request->attributes->get('membership');
        $canPublish = $access->allows($membership, 'traceability.publish');
        $canHold = $access->allows($membership, 'stock.quality.hold');
        $canRelease = $access->allows($membership, 'stock.quality.release');

        // Sum exact signed movements in source-product units, across all
        // batches; a low-stock alert is not a per-batch threshold.
        $productBalance = [];
        StockMovement::query()
            ->join('stock_batches as source_batch', function ($join) {
                $join->on('source_batch.id', '=', 'stock_movements.batch_id')
                    ->on('source_batch.business_id', '=', 'stock_movements.business_id');
            })
            ->where('stock_movements.business_id', $businessId)
            ->where('source_batch.is_quarantined', false)
            ->select(['source_batch.product_id', 'stock_movements.quantity', 'stock_movements.id'])
            ->orderBy('stock_movements.id')
            ->chunk(500, function ($lines) use (&$productBalance) {
                foreach ($lines as $line) {
                    $key = (int) $line->product_id;
                    $productBalance[$key] = ($productBalance[$key] ?? BigDecimal::zero())
                        ->plus((string) $line->quantity);
                }
            });
        $lowStock = [];
        foreach (Product::query()->where('business_id', $businessId)
            ->where('active', true)->whereNotNull('reorder_point')->orderBy('name')->get() as $product) {
            $balance = $productBalance[$product->id] ?? BigDecimal::zero();
            if ($balance->isLessThan((string) $product->reorder_point)) {
                $lowStock[] = ['product' => $product, 'available' => (string) $balance];
            }
        }

        return view($request->routeIs('stock.low-stock') ? 'receiving.low-stock' : 'receiving.stock',compact('batches','balanceByBatch','publicByBatch',
            'canPublish','canHold','canRelease','lowStock','heldCount'));
    }
}
