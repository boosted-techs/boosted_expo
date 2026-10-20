<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Services\SupplierQuoting;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierQuoteController extends Controller
{
    public function store(Request $request, int $rfq, SupplierQuoting $quotes): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'procurement.create'), 403);
        $data = $request->validate([
            'supplier_id'=>['required','integer'],
            'quantity'=>['required','numeric','gt:0','decimal:0,3','max:999999999'],
            'unit_price'=>['required','numeric','gt:0','decimal:0,4','max:99999999999'],
            'delivery_on'=>['nullable','date'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $businessId = $request->attributes->get('activeBusiness')->id;
        $supply = SupplyRequest::query()->where('business_id',$businessId)->findOrFail($rfq);
        $supplier = Supplier::query()->where('business_id',$businessId)->where('active',true)
            ->findOrFail($data['supplier_id']);
        abort_unless(SupplierRequestLink::query()->where('business_id',$businessId)
            ->where('supply_request_id',$rfq)->where('supplier_id',$supplier->id)->exists(), 422,
            'Select a supplier who received this request.');
        $quotes->record($supply,$supplier,$data,'staff_entry');

        return back()->with('status','Supplier quotation recorded.');
    }
}