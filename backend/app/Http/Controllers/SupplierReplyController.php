<?php

namespace App\Http\Controllers;

use App\Models\SupplierRequestLink;
use App\Services\SupplierQuoting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierReplyController extends Controller
{
    private function context(string $token): SupplierRequestLink
    {
        return SupplierRequestLink::query()->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('businesses')
                ->whereColumn('businesses.id', 'supplier_request_links.business_id')
                ->where('businesses.is_active', true))
            ->whereHas('supplyRequest', fn ($q) => $q->whereIn('status', ['sent', 'part_awarded'])
                ->where('response_deadline', '>', now()))
            ->whereHas('supplyRequest.items', fn ($q) => $q->whereHas('product', fn ($p) => $p->where('active', true))->whereDoesntHave('quotes', fn ($q) => $q->whereColumn('supplier_quotes.supplier_id', 'supplier_request_links.supplier_id')->where('status', 'selected')))
            ->whereHas('supplier', fn ($q) => $q->where('active', true))
            ->with(['supplyRequest.items.product', 'supplyRequest.product', 'supplier'])
            ->firstOrFail();
    }

    public function show(string $token): View
    {
        $link = $this->context($token);
        $rfq = $link->supplyRequest;
        $offer = $rfq->quotes()->where('supplier_id', $link->supplier_id)->first();

        $offers = $rfq->quotes()->where('supplier_id', $link->supplier_id)->get()->keyBy('supply_request_item_id');

        return view('procurement.supplier-reply', compact('link', 'rfq', 'offer', 'offers', 'token'));
    }

    public function store(Request $request, string $token, SupplierQuoting $quotes): RedirectResponse
    {
        $link = $this->context($token);
        if (is_array($request->input('items'))) {
            $request->merge(['items' => array_filter($request->input('items'), fn ($line) => ! is_array($line) || filled($line['quantity'] ?? null) || filled($line['unit_price'] ?? null))]);
        }
        $data = $request->validate([
            'supply_request_item_id' => ['nullable', 'integer'], 'items' => ['nullable', 'array', 'min:1', 'max:50'], 'items.*.supply_request_item_id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
            'quantity' => ['required_without:items', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'unit_price' => ['required_without:items', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
            'delivery_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($data['items'])) {
            $quotes->recordMany($link->supplyRequest, $link->supplier, $data, 'supplier_link');
        } else {
            $quotes->record($link->supplyRequest, $link->supplier, $data, 'supplier_link');
        }

        return redirect()->route('supplier-offer.show', $token)
            ->with('status', 'Quotation saved. You can revise it until the request closes.');
    }
}
