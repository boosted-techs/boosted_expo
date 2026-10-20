<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierRequestLink;
use App\Services\SupplierQuoting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupplierReplyController extends Controller
{
    private function context(string $token): SupplierRequestLink
    {
        return SupplierRequestLink::query()->where('token_hash',hash('sha256',$token))
            ->where('expires_at','>',now())
            ->whereHas('supplyRequest', fn ($q) => $q->where('status','sent')
                ->where('response_deadline','>',now())
                ->whereHas('product', fn ($p) => $p->where('active',true)))
            ->whereHas('supplier', fn ($q) => $q->where('active',true))
            ->with(['supplyRequest.product','supplier'])
            ->firstOrFail();
    }

    public function show(string $token): View
    {
        $link = $this->context($token);
        $rfq = $link->supplyRequest;
        $offer = $rfq->quotes()->where('supplier_id',$link->supplier_id)->first();

        return view('procurement.supplier-reply',compact('link','rfq','offer','token'));
    }

    public function store(Request $request, string $token, SupplierQuoting $quotes): RedirectResponse
    {
        $link = $this->context($token);
        $data = $request->validate([
            'quantity'=>['required','numeric','gt:0','decimal:0,3','max:999999999'],
            'unit_price'=>['required','numeric','gt:0','decimal:0,4','max:99999999999'],
            'delivery_on'=>['nullable','date'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $quotes->record($link->supplyRequest,$link->supplier,$data,'supplier_link');

        return redirect()->route('supplier-offer.show',$token)
            ->with('status','Quotation saved. You can revise it until the request closes.');
    }
}