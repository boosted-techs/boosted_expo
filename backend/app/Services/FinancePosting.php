<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceApprovalEvent;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinancePayment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceCreditNote;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePayrollReversal;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceForeignBankOpening;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePosting
{
    private function source(int $businessId,string $type,int $id):CommercialInvoice|FinanceSupplierBill|FinancePayment|FinanceShipmentCost|FinanceInventoryRelief|FinanceCreditNote|FinanceOpeningBalance|FinanceBalanceAdjustment|FinancePayrollRun|FinancePayrollSettlement|FinancePayrollReversal|FinanceTransactionReversal|FinanceForeignBankOpening
    {
        $model=match($type) {
            'commercial_invoice'=>CommercialInvoice::class,
            'supplier_bill'=>FinanceSupplierBill::class,
            'payment'=>FinancePayment::class,
            'shipment_cost'=>FinanceShipmentCost::class,
            'inventory_relief'=>FinanceInventoryRelief::class,
            'credit_note'=>FinanceCreditNote::class,
            'opening_balance'=>FinanceOpeningBalance::class,
            'balance_adjustment'=>FinanceBalanceAdjustment::class,
            'payroll_run'=>FinancePayrollRun::class,
            'payroll_settlement'=>FinancePayrollSettlement::class,
            'payroll_reversal'=>FinancePayrollReversal::class,
            'transaction_reversal'=>FinanceTransactionReversal::class,
            'foreign_bank_opening'=>FinanceForeignBankOpening::class,
            default=>abort(422,'This financial document type is not supported.'),
        };
        return $model::query()->where('business_id',$businessId)->lockForUpdate()->findOrFail($id);
    }

    private function state(CommercialInvoice|FinanceSupplierBill|FinancePayment|FinanceShipmentCost|FinanceInventoryRelief|FinanceCreditNote|FinanceOpeningBalance|FinanceBalanceAdjustment|FinancePayrollRun|FinancePayrollSettlement|FinancePayrollReversal|FinanceTransactionReversal|FinanceForeignBankOpening $record):string
    {
        return $record instanceof CommercialInvoice || $record instanceof FinanceSupplierBill
            || $record instanceof FinanceInventoryRelief || $record instanceof FinanceCreditNote
            || $record instanceof FinanceOpeningBalance || $record instanceof FinanceBalanceAdjustment
            || $record instanceof FinancePayrollRun || $record instanceof FinancePayrollReversal
            || $record instanceof FinanceTransactionReversal || $record instanceof FinanceForeignBankOpening
            ? $record->posting_status : $record->status;
    }

    private function event(FinancePostingRequest $request,int $actor,string $action,?string $reason=null):void
    {
        FinanceApprovalEvent::create([
            'business_id'=>$request->business_id,'posting_request_id'=>$request->id,
            'actor_id'=>$actor,'action'=>$action,'reason'=>$reason,'created_at'=>now(),
        ]);
    }

    public function request(Business $business,string $type,int $sourceId,array $data,int $userId):FinancePostingRequest
    {
        return DB::transaction(function()use($business,$type,$sourceId,$data,$userId) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(FinancePeriodControls::class)->assertWritable($business,$data['posting_on']);
            $source=$this->source($business->id,$type,$sourceId);
            abort_unless($this->state($source)===(in_array($type,['payment','shipment_cost','payroll_settlement'],true)?'pending':'not_posted'),
                422,'This financial document cannot be submitted for posting.');
            if($type==='commercial_invoice') {
                $shipment=$source->shipment;
                abort_unless(in_array($shipment->status,['dispatched','delivered'],true),422,
                    'Record shipment dispatch before submitting revenue recognition for review.');
            }
            $currency=strtoupper($source->currency);
            if($type==='inventory_relief') {
                app(FinanceInventoryValuation::class)->validateBeforePost($source);
            }
            if($type==='transaction_reversal') {
                if(($data['exchange_rate_id']??null)!==null) abort(422,
                    'A payment reversal must reuse its original journal FX rate.');
                $original=app(FinanceTransactionCorrections::class)->original($source);
                app(FinanceTransactionCorrections::class)->lines($source);
                $fx=(string)$original->rate_to_base;
            } elseif($type==='foreign_bank_opening') {
                if(($data['exchange_rate_id']??null)!==$source->exchange_rate_id) {
                    throw ValidationException::withMessages(['exchange_rate_id'=>
                        'Select the exact historical exchange rate frozen in this bank opening.']);
                }
                app(FinanceForeignBankOpenings::class)->lines($source);
                $fx=app(FinanceRates::class)->rate($business,$currency,
                    $source->exchange_rate_id,$data['posting_on']);
            } elseif(in_array($type,['payroll_settlement','payroll_reversal'],true)) {
                if(($data['exchange_rate_id']??null)!==null || $currency!==$business->base_currency) abort(422,
                    'Payroll settlements and corrections require base-currency accounting.');
                if($type==='payroll_settlement') app(FinancePayrollSettlements::class)->lines($source);
                else app(FinancePayrollCorrections::class)->lines($source);
                $fx='1';
            } elseif($type==='credit_note' || $type==='balance_adjustment') {
                if(($data['exchange_rate_id']??null)!==null) abort(422,
                    'Credits and balance concessions use the original posting exchange rate.');
                if($type==='credit_note') {
                    $original=app(FinanceCreditNotes::class)->assertEligible($source);
                } else {
                    app(FinanceBalanceAdjustments::class)->lines($source);
                    $original=FinanceJournal::query()->where('business_id',$business->id)
                        ->findOrFail($source->data_snapshot['original_journal_id']);
                }
                $fx=(string)$original->rate_to_base;
            } elseif(in_array($type,['opening_balance','payroll_run'],true)) {
                if(($data['exchange_rate_id']??null)!==null || $currency!==$business->base_currency) {
                    abort(422,'Opening balances and staff payroll must be entered in the company base currency.');
                }
                if($type==='opening_balance') app(FinanceOpeningBalances::class)->lines($source);
                else app(FinancePayroll::class)->lines($source);
                $fx='1';
            } else {
                $fx=app(FinanceRates::class)->rate($business,$currency,
                    $data['exchange_rate_id']??null,$data['posting_on']);
            }
            $date=match($type){
                'commercial_invoice'=>$source->issued_at->toDateString(),
                'supplier_bill'=>$source->vendor_invoice_date->toDateString(),
                'payment'=>$source->payment_on->toDateString(),
                'shipment_cost'=>$source->paid_on->toDateString(),
                'inventory_relief'=>$source->created_at->toDateString(),
                'credit_note'=>$source->created_at->toDateString(),
                'opening_balance'=>$source->effective_on->toDateString(),
                'balance_adjustment'=>$source->created_at->toDateString(),
                'payroll_run'=>$source->period_to->toDateString(),
                'payroll_settlement'=>$source->paid_on->toDateString(),
                'payroll_reversal'=>$source->created_at->toDateString(),
                'transaction_reversal'=>$source->created_at->toDateString(),
                'foreign_bank_opening'=>$source->effective_on->toDateString(),
            };
            if($data['posting_on']<$date) throw ValidationException::withMessages([
                'posting_on'=>'The journal date cannot precede the documented business event.',
            ]);
            $record=FinancePostingRequest::query()->where('business_id',$business->id)
                ->where('source_type',$type)->where('source_id',$sourceId)
                ->lockForUpdate()->first();
            if($record && $record->status!=='rejected') {
                abort(422,'This document already has a pending, approved or posted review.');
            }
            $entry=[
                'posting_on'=>$data['posting_on'],'exchange_rate_id'=>$data['exchange_rate_id']??null,
                'basis_notes'=>$data['basis_notes'],'requested_by'=>$userId,
                'status'=>'pending','approved_by'=>null,'approved_at'=>null,'posted_by'=>null,'posted_at'=>null,
            ];
            if($record) $record->update($entry);
            else $record=FinancePostingRequest::create([
                ...$entry,'business_id'=>$business->id,'source_type'=>$type,'source_id'=>$sourceId,
            ]);
            $this->event($record,$userId,'submitted',
                'Currency '.$currency.'; rate to base '.$fx.'; '.$data['basis_notes']);
            return $record;
        },3);
    }

    public function review(FinancePostingRequest $request,bool $approve,int $actor,string $notes):FinancePostingRequest
    {
        return DB::transaction(function()use($request,$approve,$actor,$notes){
            $request=FinancePostingRequest::query()->where('business_id',$request->business_id)
                ->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status==='pending',422,'Review is no longer pending.');
            abort_unless($request->requested_by!==$actor,422,
                'A different authorised person must review this posting request.');
            $source=$this->source($request->business_id,$request->source_type,$request->source_id);
            if($request->source_type==='credit_note') app(FinanceCreditNotes::class)->assertEligible($source);
            if($request->source_type==='inventory_relief') app(FinanceInventoryValuation::class)->validateBeforePost($source);
            if($request->source_type==='opening_balance') app(FinanceOpeningBalances::class)->lines($source);
            if($request->source_type==='balance_adjustment') app(FinanceBalanceAdjustments::class)->lines($source);
            if($request->source_type==='payroll_run') app(FinancePayroll::class)->lines($source);
            if($request->source_type==='payroll_settlement') app(FinancePayrollSettlements::class)->lines($source);
            if($request->source_type==='payroll_reversal') app(FinancePayrollCorrections::class)->lines($source);
            if($request->source_type==='transaction_reversal') app(FinanceTransactionCorrections::class)->lines($source);
            if($request->source_type==='foreign_bank_opening') app(FinanceForeignBankOpenings::class)->lines($source);
            abort_unless($this->state($source)===(
                in_array($request->source_type,['payment','shipment_cost','payroll_settlement'],true)?'pending':'not_posted'),
                422,'The source record has changed.');
            $request->update([
                'status'=>$approve?'approved':'rejected',
                'approved_by'=>$approve?$actor:null,
                'approved_at'=>$approve?now():null,
            ]);
            if(!$approve && ($source instanceof FinancePayment || $source instanceof FinanceShipmentCost
                || $source instanceof FinancePayrollSettlement)) {
                $source->update(['status'=>'rejected']);
            }
            $this->event($request,$actor,$approve?'approved':'rejected',$notes);
            return $request;
        },3);
    }

    public function post(FinancePostingRequest $request,int $actor):FinanceJournal
    {
        return DB::transaction(function()use($request,$actor){
            Business::query()->whereKey($request->business_id)->lockForUpdate()->firstOrFail();
            $request=FinancePostingRequest::query()->where('business_id',$request->business_id)
                ->lockForUpdate()->findOrFail($request->id);
            app(FinancePeriodControls::class)->assertWritable(
                Business::query()->findOrFail($request->business_id),
                $request->posting_on->toDateString());
            abort_unless($request->status==='approved' && $request->approved_by!==null,422,
                'An independent approval is required before posting.');
            abort_unless($request->approved_by!==$actor,422,
                'The reviewer cannot also post this journal.');
            $business=Business::query()->findOrFail($request->business_id);
            $source=$this->source($business->id,$request->source_type,$request->source_id);
            abort_unless($this->state($source)===(
                in_array($request->source_type,['payment','shipment_cost','payroll_settlement'],true)?'pending':'not_posted'),
                422,'The financial document is no longer pending.');
            if($request->source_type==='commercial_invoice' && !in_array(
                $source->shipment->status,['dispatched','delivered'],true)) {
                abort(422,'Shipment dispatch status has changed. Re-review recognition evidence.');
            }
            if($request->source_type==='transaction_reversal') {
                abort_unless($request->exchange_rate_id===null,422,
                    'Payment reversals must reuse the exact original FX rate.');
                $original=app(FinanceTransactionCorrections::class)->original($source);
                $rate=(string)$original->rate_to_base;
            } elseif($request->source_type==='foreign_bank_opening') {
                abort_unless($request->exchange_rate_id===$source->exchange_rate_id,422,
                    'The bank opening must use its prepared approved FX reference.');
                app(FinanceForeignBankOpenings::class)->lines($source);
                $rate=app(FinanceRates::class)->rate($business,$source->currency,
                    $source->exchange_rate_id,$request->posting_on->toDateString());
            } elseif(in_array($request->source_type,['credit_note','balance_adjustment'],true)) {
                if($request->source_type==='credit_note') {
                    $original=app(FinanceCreditNotes::class)->assertEligible($source);
                } else {
                    app(FinanceBalanceAdjustments::class)->lines($source);
                    $original=FinanceJournal::query()->where('business_id',$business->id)
                        ->findOrFail($source->data_snapshot['original_journal_id']);
                }
                $rate=(string)$original->rate_to_base;
                abort_unless($request->exchange_rate_id===null,422,'Credit rate cannot be changed.');
            } elseif(in_array($request->source_type,['opening_balance','payroll_run','payroll_settlement','payroll_reversal'],true)) {
                abort_unless($request->exchange_rate_id===null
                    && $source->currency===$business->base_currency,422);
                $rate='1';
            } else {
                $rate=app(FinanceRates::class)->rate($business,strtoupper($source->currency),
                    $request->exchange_rate_id,$request->posting_on->toDateString());
            }
            $lines=match($request->source_type) {
                'commercial_invoice'=>$this->invoiceLines($source,$rate),
                'supplier_bill'=>$this->billLines($source,$rate),
                'payment'=>$this->paymentLines($business,$source,$rate),
                'shipment_cost'=>$this->costLines($source,$rate),
                'inventory_relief'=>$this->inventoryReliefLines($source),
                'credit_note'=>app(FinanceCreditNotes::class)->lines($source),
                'opening_balance'=>app(FinanceOpeningBalances::class)->lines($source),
                'balance_adjustment'=>app(FinanceBalanceAdjustments::class)->lines($source),
                'payroll_run'=>app(FinancePayroll::class)->lines($source),
                'payroll_settlement'=>app(FinancePayrollSettlements::class)->lines($source),
                'payroll_reversal'=>app(FinancePayrollCorrections::class)->lines($source),
                'transaction_reversal'=>app(FinanceTransactionCorrections::class)->lines($source),
                'foreign_bank_opening'=>app(FinanceForeignBankOpenings::class)->lines($source),
            };
            $journal=app(FinanceLedger::class)->record(
                $business,$request->source_type,$source->id,$source->currency,$rate,
                $request->posting_on->toDateString(),
                $this->description($request->source_type,$source),$actor,$request->approved_by,$lines,
            );
            if($source instanceof CommercialInvoice) {
                $source->update(['posting_status'=>'posted']);
                $source->accountingHandoff()->update(['posting_status'=>'posted']);
            } elseif($source instanceof FinanceSupplierBill) {
                $source->update(['posting_status'=>'posted','posted_at'=>now()]);
            } elseif($source instanceof FinanceCreditNote) {
                app(FinanceCreditNotes::class)->finalize($source);
            } elseif($source instanceof FinanceInventoryRelief || $source instanceof FinanceOpeningBalance
                || $source instanceof FinanceBalanceAdjustment || $source instanceof FinancePayrollRun) {
                $source->update(['posting_status'=>'posted','posted_at'=>now()]);
            } elseif($source instanceof FinanceTransactionReversal) {
                app(FinanceTransactionCorrections::class)->finalize($source);
            } elseif($source instanceof FinanceForeignBankOpening) {
                $source->update(['posting_status'=>'posted','posted_at'=>now()]);
            } elseif($source instanceof FinancePayrollReversal) {
                app(FinancePayrollCorrections::class)->finalize($source,$actor);
            } elseif($source instanceof FinancePayment || $source instanceof FinancePayrollSettlement) {
                $source->update(['status'=>'posted','posted_at'=>now()]);
            } else {
                $source->update(['status'=>'posted','posted_at'=>now()]);
            }
            $request->update(['status'=>'posted','posted_by'=>$actor,'posted_at'=>now()]);
            $this->event($request,$actor,'posted','Journal '.$journal->reference);
            return $journal;
        },3);
    }

    private function description(string $type,$source):string
    {
        return match($type) {
            'commercial_invoice'=>'Commercial invoice '.$source->reference,
            'supplier_bill'=>'Supplier bill '.$source->reference,
            'payment'=>'Payment '.$source->reference,
            'shipment_cost'=>'Shipment cost '.$source->reference,
            'inventory_relief'=>'Inventory '.($source->kind==='cogs'?'cost of goods sold':'sorting/waste write-off').' '.$source->reference,
            'credit_note'=>'Full commercial credit '.$source->reference,
            'opening_balance'=>'Opening balances '.$source->reference,
            'balance_adjustment'=>ucfirst($source->kind).' concession '.$source->reference,
            'payroll_run'=>'Accrued staff payroll '.$source->reference,
            'payroll_settlement'=>($source->kind==='salary'?'Recorded wage settlement ':'Recorded withholding remittance ').$source->reference,
            'payroll_reversal'=>'Correction reversing payroll accrual '.$source->reference,
            'transaction_reversal'=>'Review-approved payment correction '.$source->reference,
            'foreign_bank_opening'=>'Foreign bank opening '.$source->reference,
        };
    }

    private function invoiceLines(CommercialInvoice $invoice,string $rate):array
    {
        $math=app(FinanceMath::class);$ledger=app(FinanceLedger::class);
        $total=$math->money((string)$invoice->grand_total);
        $tax=$math->money((string)$invoice->tax_amount);
        $revenue=$total->minus($tax);
        $totalBase=$math->base($total,$rate);
        $revenueBase=$math->base($revenue,$rate);
        $taxBase=$totalBase->minus($revenueBase);
        $lines=[
            $ledger->line('receivable',(string)$total,'0',(string)$totalBase,'0','Buyer amount due'),
            $ledger->line('export_sales','0',(string)$revenue,'0',(string)$revenueBase,'Recognised sales, freight and insurance'),
        ];
        if($tax->isGreaterThan(0)) {
            $lines[]=$ledger->line('sales_tax','0',(string)$tax,'0',(string)$taxBase,
                'Tax amount from manually configured commercial invoice');
        }
        return $lines;
    }

    private function billLines(FinanceSupplierBill $bill,string $rate):array
    {
        $math=app(FinanceMath::class);$ledger=app(FinanceLedger::class);
        $amount=$math->money((string)$bill->total);
        $base=$math->base($amount,$rate);
        return [
            $ledger->line('inventory',(string)$amount,'0',(string)$base,'0','Accepted produce purchase'),
            $ledger->line('payable','0',(string)$amount,'0',(string)$base,'Supplier amount due'),
        ];
    }

    private function paymentLines(Business $business,FinancePayment $payment,string $rate):array
    {
        $math=app(FinanceMath::class);$ledger=app(FinanceLedger::class);
        $amount=$math->money((string)$payment->amount);
        $cash=$math->base($amount,$rate);
        $original=$payment->direction==='in'?$payment->invoice:$payment->bill;
        if(!$original || $original->business_id!==$business->id) abort(422,'Invalid payment source.');
        $journal=FinanceJournal::query()->where('business_id',$business->id)
            ->where('source_type',$payment->direction==='in'?'commercial_invoice':'supplier_bill')
            ->where('source_id',$original->id)->firstOrFail();
        // For the final instalment consume any residual base-currency rounding difference.
        $postedBase=BigDecimal::zero();
        $postedAmount=BigDecimal::zero();
        $relation=$payment->direction==='in'?'commercial_invoice_id':'supplier_bill_id';
        foreach(FinancePayment::query()->where('business_id',$business->id)
            ->where($relation,$original->id)->where('status','posted')
            ->get(['amount','settled_base_amount']) as $prior) {
            $postedAmount=$postedAmount->plus((string)$prior->amount);
            $postedBase=$postedBase->plus((string)$prior->settled_base_amount);
        }
        $originalTotal=BigDecimal::of((string)($payment->direction==='in'?$original->grand_total:$original->total));
        $adjustment=FinanceBalanceAdjustment::query()->where('business_id',$business->id)
            ->where($relation,$original->id)->where('posting_status','posted')->first();
        if($adjustment) $originalTotal=$originalTotal->minus((string)$adjustment->amount);
        if($postedAmount->plus($amount)->isGreaterThan($originalTotal)) {
            throw ValidationException::withMessages(['amount'=>'Payment exceeds the outstanding balance.']);
        }
        $originalBase=$math->base($originalTotal,(string)$journal->rate_to_base);
        $settled=$postedAmount->plus($amount)->isEqualTo($originalTotal)
            ?$originalBase->minus($postedBase)
            :$math->base($amount,(string)$journal->rate_to_base);
        $payment->update(['settled_base_amount'=>(string)$settled]);
        $cashAccount=$payment->cashAccount;
        abort_unless($cashAccount?->business_id===$business->id,422);
        app(FinanceBankProfiles::class)->assertPaymentAccount($cashAccount,$payment->currency);
        if($payment->direction==='in') {
            $lines=[
                $ledger->line($cashAccount->system_key,(string)$amount,'0',(string)$cash,'0','Manually recorded customer receipt'),
                $ledger->line('receivable','0',(string)$amount,'0',(string)$settled,'Receivable allocation'),
            ];
            $difference=$cash->minus($settled);
        } else {
            $lines=[
                $ledger->line('payable',(string)$amount,'0',(string)$settled,'0','Supplier balance settled'),
                $ledger->line($cashAccount->system_key,'0',(string)$amount,'0',(string)$cash,'Manual supplier disbursement'),
            ];
            $difference=$settled->minus($cash);
        }
        if($difference->isGreaterThan(0)) {
            $lines[]=$ledger->line('fx_gain','0','0','0',(string)$difference,'Realised exchange-rate gain');
        } elseif($difference->isLessThan(0)) {
            $lines[]=$ledger->line('fx_loss','0','0',(string)$difference->negated(),'0','Realised exchange-rate loss');
        }
        return $lines;
    }

    private function inventoryReliefLines(FinanceInventoryRelief $relief):array
    {
        app(FinanceInventoryValuation::class)->validateBeforePost($relief);
        $amount=app(FinanceMath::class)->money((string)$relief->base_amount);
        $ledger=app(FinanceLedger::class);
        return [
            $ledger->line($relief->kind==='cogs'?'cogs':'waste_loss',
                (string)$amount,'0',(string)$amount,'0','Source-batch inventory consumption'),
            $ledger->line('inventory','0',(string)$amount,'0',(string)$amount,
                'Credited against the posted inventory valuation'),
        ];
    }

    private function costLines(FinanceShipmentCost $cost,string $rate):array
    {
        $math=app(FinanceMath::class);$ledger=app(FinanceLedger::class);
        $amount=$math->money((string)$cost->amount);$base=$math->base($amount,$rate);
        $cash=$cost->cashAccount;
        abort_unless($cash && $cash->business_id===$cost->business_id,422);
        app(FinanceBankProfiles::class)->assertPaymentAccount($cash,$cost->currency);
        return [
            $ledger->line('shipment_cost',(string)$amount,'0',(string)$base,'0',$cost->description),
            $ledger->line($cash->system_key,'0',(string)$amount,'0',(string)$base,'Manually recorded expense payment'),
        ];
    }
}
