<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Models\FinancePayment;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceDocuments
{
    public function supplierBill(Business $business,PurchaseOrder $purchase,array $data,int $actor):FinanceSupplierBill
    {
        return DB::transaction(function()use($business,$purchase,$data,$actor){
            $purchase=PurchaseOrder::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($purchase->id);
            abort_unless($purchase->status==='received',422,
                'Complete goods receiving before preparing the supplier bill.');
            abort_unless(!$purchase->receipts()->where('accepted_quantity','>',0)->doesntExist(),422,
                'This purchase has no accepted goods to bill.');
            abort_unless(!FinanceSupplierBill::query()->where('business_id',$business->id)
                ->where('purchase_order_id',$purchase->id)->exists(),422,
                'This purchase already has a supplier bill.');
            $math=app(FinanceMath::class);
            $accepted=BigDecimal::zero();
            foreach($purchase->receipts()->pluck('accepted_quantity') as $quantity) {
                $accepted=$accepted->plus((string)$quantity);
            }
            if($accepted->isLessThanOrEqualTo(0)) throw ValidationException::withMessages([
                'purchase'=>'No accepted produce is available to bill.',
            ]);
            $total=$math->amount($accepted,(string)$purchase->unit_price);
            return FinanceSupplierBill::create([
                'business_id'=>$business->id,'purchase_order_id'=>$purchase->id,
                'reference'=>'BILL-'.Str::upper((string)Str::ulid()),
                'vendor_invoice_reference'=>$data['vendor_invoice_reference'],
                'vendor_invoice_date'=>$data['vendor_invoice_date'],
                'accepted_quantity'=>(string)$accepted,'total'=>(string)$total,
                'currency'=>$purchase->currency,'notes'=>$data['notes']??null,
                'created_by'=>$actor,'posting_status'=>'not_posted',
            ]);
        },3);
    }

    public function outstanding(CommercialInvoice|FinanceSupplierBill $source,bool $includePending=true):BigDecimal
    {
        if($source instanceof CommercialInvoice && $source->posting_status==='credited') {
            return BigDecimal::zero();
        }
        $base=BigDecimal::of((string)($source instanceof CommercialInvoice
            ? $source->grand_total : $source->total));
        $relation=$source instanceof CommercialInvoice ? 'commercial_invoice_id' : 'supplier_bill_id';
        $adjustment=\App\Models\FinanceBalanceAdjustment::query()
            ->where('business_id',$source->business_id)
            ->where($relation,$source->id)
            ->whereIn('posting_status',$includePending?['not_posted','posted']:['posted'])
            ->first();
        if($adjustment) $base=$base->minus((string)$adjustment->amount);
        $statuses=$includePending?['pending','posted']:['posted'];
        $allocated=BigDecimal::zero();
        foreach(FinancePayment::query()->where('business_id',$source->business_id)
            ->where($relation,$source->id)->whereIn('status',$statuses)
            ->get(['id','amount','status']) as $payment) {
            $allocated=$allocated->plus((string)$payment->amount);
            if($payment->status==='posted') {
                foreach(FinancePartialBankReturn::query()->where('business_id',$source->business_id)
                    ->where('source_type','payment')->where('source_id',$payment->id)
                    ->where('posting_status','posted')->pluck('amount') as $returned) {
                    $allocated=$allocated->minus((string)$returned);
                }
            }
        }
        return $base->minus($allocated);
    }

    public function payment(Business $business,array $data,int $actor):FinancePayment
    {
        return DB::transaction(function()use($business,$data,$actor){
            $existing=FinancePayment::query()->where('business_id',$business->id)
                ->where('idempotency_key',$data['idempotency_key'])->first();
            if($existing) return $existing;
            $type=$data['direction'];
            abort_unless(in_array($type,['in','out'],true),422);
            $math=app(FinanceMath::class);
            $amount=$math->money($data['amount']);
            if($amount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['amount'=>'Payment amount must be positive.']);
            }
            if($type==='in') {
                $document=CommercialInvoice::query()->where('business_id',$business->id)
                    ->lockForUpdate()->findOrFail($data['commercial_invoice_id']);
                abort_unless($document->posting_status==='posted',422,
                    'The commercial invoice must be approved and posted before recording receipts.');
                abort_unless(!\App\Models\FinanceCreditNote::query()
                    ->where('business_id',$business->id)
                    ->where('commercial_invoice_id',$document->id)->exists(),422,
                    'An invoice credit is under review or already posted. Do not allocate further payments.');
            } else {
                $document=FinanceSupplierBill::query()->where('business_id',$business->id)
                    ->lockForUpdate()->findOrFail($data['supplier_bill_id']);
                abort_unless($document->posting_status==='posted',422,
                    'The supplier bill must be approved and posted before making payments.');
            }
            if(\App\Models\FinancePartialBankReturn::query()
                ->where('business_id',$business->id)
                ->where('source_type','payment')
                ->whereIn('source_id',FinancePayment::query()->where('business_id',$business->id)
                    ->where($type==='in'?'commercial_invoice_id':'supplier_bill_id',$document->id)
                    ->select('id'))
                ->where('posting_status','not_posted')->exists()) {
                throw ValidationException::withMessages(['document'=>
                    'A bank return on this document is awaiting finance posting. Complete it before recording new payments.']);
            }
            if(\App\Models\FinanceBalanceAdjustment::query()
                ->where('business_id',$business->id)
                ->where($type==='in'?'commercial_invoice_id':'supplier_bill_id',$document->id)
                ->where('posting_status','not_posted')->exists()) {
                throw ValidationException::withMessages(['document'=>
                    'Finish the pending balance-concession approval before recording a payment for this document.']);
            }
            $remaining=$this->outstanding($document);
            if($amount->isGreaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'amount'=>'This payment exceeds the remaining document amount, including pending allocations.',
                ]);
            }
            $account=app(FinanceBankProfiles::class)->account($business,
                isset($data['bank_profile_id'])?(int)$data['bank_profile_id']:null,
                $data['cash_account_key'],$document->currency);
            return FinancePayment::create([
                'business_id'=>$business->id,'reference'=>'PAY-'.Str::upper((string)Str::ulid()),
                'direction'=>$type,'commercial_invoice_id'=>$type==='in'?$document->id:null,
                'supplier_bill_id'=>$type==='out'?$document->id:null,
                'cash_account_id'=>$account->id,'currency'=>$document->currency,
                'amount'=>(string)$amount,'payment_on'=>$data['payment_on'],
                'external_reference'=>$data['external_reference'],
                'status'=>'pending','notes'=>$data['notes']??null,
                'idempotency_key'=>$data['idempotency_key'],'created_by'=>$actor,
            ]);
        },3);
    }

    public function shipmentCost(Business $business,array $data,int $actor):FinanceShipmentCost
    {
        return DB::transaction(function()use($business,$data,$actor){
            $shipment=ExportShipment::query()->where('business_id',$business->id)
                ->findOrFail($data['shipment_id']);
            abort_unless($shipment->status!=='cancelled',422,
                'Cancelled shipments cannot accept newly recorded costs.');
            $amount=app(FinanceMath::class)->money($data['amount']);
            abort_unless($amount->isGreaterThan(0),422,'Cost amount must be positive.');
            $account=app(FinanceBankProfiles::class)->account($business,
                isset($data['bank_profile_id'])?(int)$data['bank_profile_id']:null,
                $data['cash_account_key'],strtoupper($data['currency']));
            return FinanceShipmentCost::create([
                'business_id'=>$business->id,'reference'=>'COST-'.Str::upper((string)Str::ulid()),
                'shipment_id'=>$shipment->id,'category'=>$data['category'],
                'description'=>$data['description'],'currency'=>strtoupper($data['currency']),
                'amount'=>(string)$amount,'cash_account_id'=>$account->id,
                'paid_on'=>$data['paid_on'],'external_reference'=>$data['external_reference'],
                'status'=>'pending','created_by'=>$actor,
            ]);
        });
    }
}
