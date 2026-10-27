<?php

namespace App\Http\Controllers;

use App\Models\FinanceExchangeRate;
use App\Models\OrderAmendment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\OrderAmendments;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderAmendmentController extends Controller
{
    private function check(Request $r, string $permission = 'sales.orders.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r, int $order): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $order = SalesOrder::query()->where('business_id', $business->id)->with('customer', 'lines')->findOrFail($order);
        $r->validate(['product_search' => ['nullable', 'string', 'max:120']]);
        $products = Product::query()->where('business_id', $business->id)->where('active', true)->when($r->filled('product_search'), fn ($q) => $q->where('name', 'like', '%'.$r->input('product_search').'%'))->orderBy('name')->paginate(40, ['*'], 'product_page')->withQueryString();
        $amendments = OrderAmendment::query()->where('business_id', $business->id)->where('sales_order_id', $order->id)->latest('id')->paginate(20, ['*'], 'amendment_page')->withQueryString();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('currency', $order->currency)->where('status', 'approved')->latest('effective_on')->get();
        $access = app(BusinessPermissions::class);
        $member = $r->attributes->get('membership');
        $canCreate = $access->allows($member, 'sales.manage');
        $canReview = $access->allows($member, 'sales.orders.amend.review');

        return view('sales.orders.amendments', compact('business', 'order', 'products', 'amendments', 'rates', 'canCreate', 'canReview'));
    }

    public function store(Request $r, int $order, OrderAmendments $service): RedirectResponse
    {
        $this->check($r, 'sales.manage');
        if (is_array($r->input('lines'))) {
            $r->merge(['lines' => array_values(array_filter($r->input('lines'), fn ($row) => ! is_array($row) || collect($row)->contains(fn ($value) => filled($value))))]);
        }$data = $r->validate(['lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.id' => ['nullable', 'integer', 'min:1'], 'lines.*.product_id' => ['required_without:lines.*.id', 'nullable', 'integer', 'min:1'], 'lines.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'], 'lines.*.closed_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'], 'lines.*.unit_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999999999'], 'required_on' => ['nullable', 'date_format:Y-m-d'], 'destination' => ['nullable', 'string', 'max:250'], 'discount_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'], 'tax_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'], 'exchange_rate_id' => ['nullable', 'integer', 'min:1'], 'buyer_mode' => ['required', 'in:external,link'], 'buyer_name' => ['required_if:buyer_mode,external', 'nullable', 'string', 'max:150'], 'buyer_reference' => ['required_if:buyer_mode,external', 'nullable', 'string', 'max:200'], 'buyer_evidence' => ['required_if:buyer_mode,external', 'nullable', 'string', 'min:20', 'max:5000'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $b = $r->attributes->get('activeBusiness');
        $service->propose($b, SalesOrder::query()->where('business_id', $b->id)->findOrFail($order), $data, $r->user()->id);

        return back()->with('status', 'Order changes proposed. Quantities, source coverage and prices stay unchanged until the required reviews and buyer agreement are complete.');
    }

    public function review(Request $r, int $amendment, string $decision, OrderAmendments $service): RedirectResponse
    {
        $this->check($r, 'sales.orders.amend.review');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $service->review($b, OrderAmendment::query()->where('business_id', $b->id)->findOrFail($amendment), $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Order amendment reviewed. A link-based proposal still awaits the buyer’s decision.');
    }

    public function link(Request $r, int $amendment, OrderAmendments $service): RedirectResponse
    {
        $this->check($r, 'sales.manage');
        $b = $r->attributes->get('activeBusiness');
        $token = $service->link($b, OrderAmendment::query()->where('business_id', $b->id)->findOrFail($amendment), $r->user()->id);

        return back()->with('amendment_url', route('buyer-amendment.show', $token))->with('status', 'Private buyer review link created. Share it with the intended buyer; no message was sent.');
    }

    public function withdraw(Request $r, int $amendment, OrderAmendments $service): RedirectResponse
    {
        $this->check($r, 'sales.manage');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $b = $r->attributes->get('activeBusiness');
        $service->withdraw($b, OrderAmendment::query()->where('business_id', $b->id)->findOrFail($amendment), $data['reason'], $r->user()->id);

        return back()->with('status', 'Undecided order amendment withdrawn and its buyer link revoked.');
    }
}
