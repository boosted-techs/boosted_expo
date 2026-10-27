<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOrderingLink;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\SalesRequest;
use App\Services\CustomerOrdering;
use App\Services\OrderOperations;
use App\Services\PlatformAccess;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerOrderingController extends Controller
{
    public function invite(Request $request, int $customer, CustomerOrdering $ordering): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage'), 403);
        $b = $request->attributes->get('activeBusiness');
        $c = Customer::query()->where('business_id', $b->id)->findOrFail($customer);
        $data = $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:200'], 'product_ids.*' => ['required', 'integer', 'distinct']]);
        $ordering->invite($b, $c, $data['product_ids'], $request->user()->id);

        return back()->with('status', 'Customer ordering invitation recorded. Review its delivery status; a failed delivery link is disabled.');
    }

    public function revoke(Request $request, int $link): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage'), 403);
        $b = $request->attributes->get('activeBusiness');
        $l = CustomerOrderingLink::query()->where('business_id', $b->id)->findOrFail($link);
        $l->update(['revoked_at' => now()]);
        app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'customer.ordering_revoked', 'Customer ordering link revoked.', [], ['link_id' => $l->id]);

        return back()->with('status', 'Customer ordering access revoked.');
    }

    public function show(string $token, CustomerOrdering $ordering): View
    {
        $context = $ordering->resolve($token);
        $products = Product::query()->where('business_id', $context['business']->id)->where('active', true)->whereIn('id', $context['link']->product_ids)->orderBy('name')->get();
        $requests = SalesRequest::query()->where('business_id', $context['business']->id)->where('customer_id', $context['customer']->id)->with('items.product', 'order.lines')->latest('id')->paginate(15);

        return view('buyer.ordering', [...$context, 'products' => $products, 'requests' => $requests, 'token' => $token]);
    }

    public function store(Request $request, string $token, CustomerOrdering $ordering): RedirectResponse
    {
        $data = $request->validate(['submission_key' => ['required', 'uuid'], 'buyer_reference' => ['nullable', 'string', 'max:120'], 'destination' => ['required', 'string', 'max:200'], 'required_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.today('Africa/Kampala')->toDateString()], 'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.product_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999']]);
        $ordering->submit($token, $data);

        return redirect()->route('customer-ordering.show', $token)->with('status', 'Your request was submitted for review. No selling price is assumed.');
    }

    public function order(string $token, int $record, CustomerOrdering $ordering): View
    {
        $c = $ordering->resolve($token);
        $request = SalesRequest::query()->where('business_id', $c['business']->id)->where('customer_id', $c['customer']->id)->with('items.product', 'order.lines', 'order.shipments')->findOrFail($record);
        $summary = $request->order ? app(OrderOperations::class)->progress($request->order) : null;
        $updates = $request->order ? OrderEvent::query()->where('business_id', $c['business']->id)->where('sales_order_id', $request->order->id)->whereNotNull('customer_note')->latest('id')->get(['customer_note', 'created_at']) : collect();

        return view('buyer.request', [...$c, 'record' => $request, 'summary' => $summary, 'updates' => $updates, 'token' => $token]);
    }
}
