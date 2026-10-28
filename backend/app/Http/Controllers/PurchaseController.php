<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\MarketTrip;
use App\Models\OrderSupply;
use App\Models\Product;
use App\Models\PurchaseAmendment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\OrderSourcing;
use App\Services\PlatformAccess;
use App\Services\ProcurementMath;
use App\Services\PurchaseAmendments;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function own(Request $request, int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'purchases.view');
        $business = $request->attributes->get('activeBusiness');
        $archived = $request->routeIs('purchases.archived');
        $purchases = PurchaseOrder::query()->when($archived, fn ($q) => $q->onlyTrashed())->where('business_id', $business->id)
            ->with(['product', 'supplier', 'receipts'])->latest()->workspacePaginate(20);
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'purchases.create');
        $canReceive = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'receipts.post');

        return view('purchases.index', compact('purchases', 'canCreate', 'canReceive', 'archived'));
    }

    public function create(Request $request): View
    {
        $this->check($request, 'purchases.create');
        $business = $request->attributes->get('activeBusiness');
        $products = Product::query()->where('business_id', $business->id)->where('active', true)
            ->orderBy('name')->get();
        $suppliers = Supplier::query()->where('business_id', $business->id)->where('active', true)
            ->orderBy('name')->get();
        $formKey = (string) Str::uuid();

        return view('purchases.create', compact('products', 'suppliers', 'formKey', 'business'));
    }

    public function store(Request $request, ProcurementMath $math): RedirectResponse
    {
        $this->check($request, 'purchases.create');
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['market', 'walk_in', 'supplier'])],
            'product_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'vendor_phone' => ['nullable', 'string', 'max:50'],
            'market_location' => ['nullable', 'string', 'max:150'],
            'purchased_on' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
            'currency' => ['required', 'string', app(BusinessCurrencies::class)->rule($request->attributes->get('activeBusiness'))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'market_trip_id' => ['nullable', 'integer'], 'sales_order_line_id' => ['nullable', 'integer'], 'origin_supply_id' => ['nullable', 'integer'], 'expected_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $business = $request->attributes->get('activeBusiness');
        Product::query()->where('business_id', $business->id)->where('active', true)
            ->findOrFail($data['product_id']);
        if ($data['kind'] === 'supplier') {
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages(['supplier_id' => 'Choose a registered supplier.']);
            }
            Supplier::query()->where('business_id', $business->id)->where('active', true)
                ->findOrFail($data['supplier_id']);
        } else {
            $data['supplier_id'] = null;
        }

        $order = DB::transaction(function () use ($data, $business, $request, $math) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if (! empty($data['market_trip_id'])) {
                MarketTrip::query()->where('business_id', $business->id)->where('status', '!=', 'closed')->findOrFail($data['market_trip_id']);
            }
            $existing = PurchaseOrder::withTrashed()->where('business_id', $business->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->trashed(), 422, 'This purchase was archived. Restore it before reusing this purchase reference.');
                abort_unless($existing->product_id === (int) $data['product_id'] && $existing->kind === $data['kind'] && $existing->currency === $data['currency'] && $math->decimal($existing->quantity)->isEqualTo($math->decimal($data['quantity'])) && $math->decimal($existing->unit_price)->isEqualTo($math->decimal($data['unit_price'])), 409, 'This purchase key was already used for a different purchase.');
                if (! empty($data['sales_order_line_id'])) {
                    abort_unless(OrderSupply::query()->where('business_id', $business->id)->where('purchase_order_id', $existing->id)->where('sales_order_line_id', $data['sales_order_line_id'])->exists(), 409, 'This purchase key belongs to a different allocation.');
                }

                return $existing;
            }

            $purchase = PurchaseOrder::create([
                ...$data, 'business_id' => $business->id,
                'reference' => 'PO-'.Str::upper((string) Str::ulid()),
                'currency' => strtoupper($data['currency']),
                'estimated_total' => $math->amount($data['unit_price'], $data['quantity']),
                'created_by' => $request->user()->id,
                'status' => 'ordered',
            ]);
            if (! empty($data['sales_order_line_id'])) {
                $source = app(OrderSourcing::class);
                $line = $source->line($business->id, (int) $data['sales_order_line_id']);
                $source->supply($line, ['kind' => 'purchase', 'purchase_order_id' => $purchase->id, 'origin_supply_id' => $data['origin_supply_id'] ?? null, 'quantity' => $data['quantity'], 'reason' => 'Purchase created against approved demand.', 'request_key' => $data['idempotency_key']], $request->user()->id);
            }

            return $purchase;
        });

        return redirect()->route('purchases.show', $order->id)
            ->with('status', 'Purchase recorded. Goods will enter stock only after receiving.');
    }

    public function amend(Request $request, int $purchase, PurchaseAmendments $amendments): RedirectResponse
    {
        $this->check($request, 'purchases.create');
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'vendor_name' => ['nullable', 'string', 'max:160'], 'vendor_phone' => ['nullable', 'string', 'max:50'], 'expected_on' => ['nullable', 'date_format:Y-m-d'], 'agreement_reference' => ['required', 'string', 'min:3', 'max:200'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $amendments->propose($request->attributes->get('activeBusiness'), $this->own($request, $purchase), $data, $request->user()->id);

        return back()->with('status', 'Purchase amendment prepared for independent agreement review.');
    }

    public function reviewAmendment(Request $request, int $amendment, string $decision, PurchaseAmendments $amendments): RedirectResponse
    {
        $this->check($request, 'purchases.amend.review');
        $data = $request->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $request->attributes->get('activeBusiness');
        $amendments->review($b, PurchaseAmendment::query()->where('business_id', $b->id)->findOrFail($amendment), $decision === 'approve', $data['note'], $request->user()->id);

        return back()->with('status', 'Purchase agreement reviewed with original terms preserved.');
    }

    public function archive(Request $request, int $purchase): RedirectResponse
    {
        $this->check($request, 'purchases.create');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $business = $request->attributes->get('activeBusiness');
        DB::transaction(function () use ($request, $purchase, $business, $data): void {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $record = PurchaseOrder::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($purchase);
            abort_if($record->supplier_quote_id, 422, 'Selected supplier purchases must be changed through agreement review.');
            abort_unless($record->status === 'ordered' && ! $record->receipts()->exists(), 422, 'Received or completed purchases cannot be archived.');
            foreach (['order_supplies', 'finance_supplier_bills', 'purchase_amendments'] as $table) {
                abort_if(DB::table($table)->where('business_id', $business->id)->where('purchase_order_id', $record->id)->exists(), 422, 'Resolve linked orders, bills and amendments before archiving.');
            }
            $record->delete();
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'purchase.archived', $data['reason'], [], ['purchase_order_id' => $record->id]);
        });

        return redirect()->route('purchases.archived')->with('status', 'Purchase archived. You can restore it from Archived purchases.');
    }

    public function restore(Request $request, int $purchase): RedirectResponse
    {
        $this->check($request, 'purchases.create');
        $record = PurchaseOrder::onlyTrashed()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($purchase);
        $record->restore();
        app(PlatformAccess::class)->audit($record->business_id, $request->user()->id, 'purchase.restored', 'Archived purchase restored.', [], ['purchase_order_id' => $record->id]);

        return redirect()->route('purchases.show', $record->id)->with('status', 'Purchase restored.');
    }

    public function show(Request $request, int $purchase): View
    {
        $this->check($request, 'purchases.view');
        $business = $request->attributes->get('activeBusiness');
        $purchase = $this->own($request, $purchase)->load(['product', 'supplier', 'receipts.batch']);
        $canReceive = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'receipts.post');
        $remaining = app(ProcurementMath::class)->decimal($purchase->quantity)->minus(
            app(ProcurementMath::class)->sum($purchase->receipts->pluck('received_quantity')->all())
        );

        $amendments = PurchaseAmendment::query()->where('business_id', $purchase->business_id)->where('purchase_order_id', $purchase->id)->latest('id')->workspacePaginate(15);
        $canAmend = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'purchases.create');
        $canReview = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'purchases.amend.review');
        $suppliers = Supplier::query()->where('business_id', $purchase->business_id)->where('active', true)->orderBy('name')->get();

        return view('purchases.show', compact('business', 'purchase', 'remaining', 'canReceive', 'amendments', 'canAmend', 'canReview', 'suppliers'));
    }
}
