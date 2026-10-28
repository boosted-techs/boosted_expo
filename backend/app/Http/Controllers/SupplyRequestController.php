<?php

namespace App\Http\Controllers;

use App\Mail\SupplierRequestMail;
use App\Models\Business;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Services\PlatformAccess;
use App\Services\ProcurementMath;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupplyRequestController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);
    }

    private function own(Request $request, int $id): SupplyRequest
    {
        return SupplyRequest::query()->where('business_id', $request->attributes->get('activeBusiness')->id)
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'procurement.view');
        $businessId = $request->attributes->get('activeBusiness')->id;
        $requests = SupplyRequest::query()->where('business_id', $businessId)
            ->with('items.product')->withCount('quotes')->latest()->workspacePaginate(20);
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'procurement.create');

        return view('procurement.index', compact('requests', 'canCreate'));
    }

    public function create(Request $request): View
    {
        $this->check($request, 'procurement.create');
        $products = Product::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->where('active', true)->orderBy('name')->get();

        return view('procurement.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request, 'procurement.create');
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'product_id' => ['required_without:items', 'integer'],
            'quantity' => ['required_without:items', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'items' => ['nullable', 'array', 'min:1', 'max:50'], 'items.*.product_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'currency' => ['required', 'string', app(BusinessCurrencies::class)->rule($request->attributes->get('activeBusiness'))],
            'needed_on' => ['nullable', 'date'],
            'response_deadline' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $business = $request->attributes->get('activeBusiness');
        $items = $data['items'] ?? [['product_id' => $data['product_id'], 'quantity' => $data['quantity']]];
        $rfq = DB::transaction(function () use ($business, $items, $data, $request) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $products = Product::query()->where('business_id', $business->id)->where('active', true)->whereIn('id', array_column($items, 'product_id'))->get();
            if ($products->count() !== count($items)) {
                throw ValidationException::withMessages(['items' => 'Choose different active products from this business.']);
            }
            $first = $items[0];
            $record = SupplyRequest::create([...collect($data)->except(['items', 'product_id', 'quantity'])->all(), ...$first, 'business_id' => $business->id, 'reference' => 'RFQ-'.Str::upper((string) Str::ulid()), 'status' => 'draft', 'created_by' => $request->user()->id]);
            foreach (array_slice($items, 1) as $line) {
                $record->items()->create([...$line, 'business_id' => $business->id]);
            }
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'supply_request.created', 'Supply request added.', [], ['id' => $record->id, 'items' => $items]);

            return $record;
        });

        return redirect()->route('supply-requests.show', $rfq->id)
            ->with('status', 'Supply request saved. Choose suppliers to issue it.');
    }

    public function show(Request $request, int $rfq): View
    {
        $this->check($request, 'procurement.view');
        $rfq = $this->own($request, $rfq)->load([
            'items.product', 'product', 'links.supplier', 'quotes.supplier', 'quotes.item.product', 'quotes.revisions',
        ]);
        $suppliers = Supplier::query()->where('business_id', $rfq->business_id)
            ->where('active', true)->orderBy('name')->get();
        $purchases = PurchaseOrder::query()->where('business_id', $rfq->business_id)
            ->whereIn('supplier_quote_id', $rfq->quotes->pluck('id'))->with('product', 'supplier')->get();
        $math = app(ProcurementMath::class);
        $remaining = $rfq->items->mapWithKeys(fn ($item) => [$item->id => $math->decimal($item->quantity)->minus($math->sum($purchases->whereIn('supplier_quote_id', $rfq->quotes->where('supply_request_item_id', $item->id)->pluck('id'))->pluck('quantity')->all()))]);
        $canCreate = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'procurement.create');
        $canAward = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'procurement.award');

        return view('procurement.show', compact('rfq', 'suppliers', 'purchases', 'remaining', 'canCreate', 'canAward'));
    }

    public function issue(Request $request, int $rfq): RedirectResponse
    {
        $this->check($request, 'procurement.create');
        $data = $request->validate([
            'supplier_ids' => ['required', 'array', 'min:1', 'max:20'],
            'supplier_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = $data['supplier_ids'];
        $rfq = $this->own($request, $rfq);
        $suppliers = Supplier::query()->where('business_id', $rfq->business_id)
            ->where('active', true)->whereIn('id', $ids)->get();
        if ($suppliers->count() !== count($ids) || $suppliers->contains(fn ($s) => ! $s->email)) {
            throw ValidationException::withMessages(['supplier_ids' => 'Select active registered suppliers with email addresses.']);
        }
        $links = DB::transaction(function () use ($rfq, $suppliers) {
            $locked = SupplyRequest::query()->where('business_id', $rfq->business_id)
                ->lockForUpdate()->findOrFail($rfq->id);
            abort_unless($locked->status === 'draft' && $locked->response_deadline->isFuture(), 422,
                'This supply request cannot be issued.');
            $result = [];
            foreach ($suppliers as $supplier) {
                $token = Str::random(64);
                $link = SupplierRequestLink::create([
                    'business_id' => $locked->business_id, 'supply_request_id' => $locked->id,
                    'supplier_id' => $supplier->id, 'token_hash' => hash('sha256', $token),
                    'expires_at' => $locked->response_deadline,
                ]);
                $result[] = compact('supplier', 'token', 'link');
            }
            $locked->update(['status' => 'sent']);

            return $result;
        });

        $failures = 0;
        foreach ($links as $item) {
            try {
                Mail::to($item['supplier']->email)->send(
                    new SupplierRequestMail($rfq->load('items.product'), $item['supplier'], $item['token'])
                );
                $item['link']->update(['sent_at' => now()]);
            } catch (\Throwable $error) {
                report($error);
                $failures++;
            }
        }
        $message = $failures
            ? 'Request issued. '.$failures.' supplier invitation(s) could not be delivered. Check email settings.'
            : (config('mail.default') === 'log'
                ? 'Request issued. Email delivery is not configured; ask your administrator to enable it.'
                : 'Supply request issued to selected suppliers.');

        return redirect()->route('supply-requests.show', $rfq->id)->with('status', $message);
    }

    public function award(Request $request, int $rfq, int $quote, ProcurementMath $math): RedirectResponse
    {
        $this->check($request, 'procurement.award');
        $data = $request->validate(['quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999']]);
        $businessId = $request->attributes->get('activeBusiness')->id;
        $order = DB::transaction(function () use ($businessId, $rfq, $quote, $math, $request, $data) {
            $business = Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $supply = SupplyRequest::query()->where('business_id', $businessId)->lockForUpdate()->findOrFail($rfq);
            abort_unless(in_array($supply->status, ['sent', 'part_awarded'], true), 422, 'This request cannot be awarded.');
            $offer = SupplierQuote::query()->where('business_id', $businessId)
                ->where('supply_request_id', $supply->id)
                ->findOrFail($quote);
            abort_unless($offer->status === 'submitted', 422, 'This offer is already awarded.');
            $item = $supply->items()->where('business_id', $businessId)->lockForUpdate()->findOrFail($offer->supply_request_item_id);
            $quantity = $data['quantity'] ?? $offer->quantity;
            $awarded = $math->sum(PurchaseOrder::query()->where('business_id', $businessId)->whereIn('supplier_quote_id', $supply->quotes()->where('supply_request_item_id', $item->id)->select('id'))->pluck('quantity')->all());
            if ($math->decimal($quantity)->isGreaterThan($math->decimal($offer->quantity)) || $awarded->plus($quantity)->isGreaterThan($math->decimal($item->quantity))) {
                throw ValidationException::withMessages(['quantity' => 'Award exceeds the offer or remaining RFQ quantity.']);
            }
            $order = PurchaseOrder::create([
                'business_id' => $businessId,
                'reference' => 'PO-'.Str::upper((string) Str::ulid()),
                'kind' => 'supplier', 'product_id' => $item->product_id,
                'supplier_id' => $offer->supplier_id, 'supplier_quote_id' => $offer->id,
                'purchased_on' => now($business->timezone)->toDateString(),
                'quantity' => $quantity, 'unit_price' => $offer->unit_price,
                'estimated_total' => $math->amount($offer->unit_price, $quantity),
                'expected_on' => $offer->delivery_on, 'currency' => $offer->currency, 'status' => 'ordered',
                'notes' => 'Awarded from '.$supply->reference,
                'idempotency_key' => (string) Str::uuid(), 'created_by' => $request->user()->id,
            ]);
            $offer->update(['status' => 'selected']);
            $complete = $supply->items()->get()->every(function ($line) use ($businessId, $supply, $math) {
                $total = $math->sum(PurchaseOrder::query()->where('business_id', $businessId)->whereIn('supplier_quote_id', $supply->quotes()->where('supply_request_item_id', $line->id)->select('id'))->pluck('quantity')->all());

                return $total->isEqualTo($math->decimal($line->quantity));
            });
            $supply->update(['status' => $complete ? 'awarded' : 'part_awarded']);
            app(PlatformAccess::class)->audit($businessId, $request->user()->id, 'supplier_quote.awarded', 'Supplier offer selected.', [], ['supply_request_id' => $supply->id, 'item_id' => $item->id, 'quote_id' => $offer->id, 'purchase_order_id' => $order->id, 'quantity' => $quantity]);

            return $order;
        });

        return redirect()->route('purchases.show', $order->id)
            ->with('status', 'Supplier selected and purchase order created. Awaiting goods receipt.');
    }
}
