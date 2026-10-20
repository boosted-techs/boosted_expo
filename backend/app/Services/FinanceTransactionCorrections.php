<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceBankMatch;
use App\Models\FinanceJournal;
use App\Models\FinancePayment;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceTransactionCorrections
{
    private const SOURCES=[
        'payment'=>FinancePayment::class,
        'shipment_cost'=>FinanceShipmentCost::class,
        'payroll_settlement'=>FinancePayrollSettlement::class,
    ];

    private function source(int $businessId,string $type,int $id)
        :FinancePayment|FinanceShipmentCost|FinancePayrollSettlement
    {
        abort_unless(isset(self::SOURCES[$type]),404);
        return self::SOURCES[$type]::query()->where('business_id',$businessId)
            ->lockForUpdate()->findOrFail($id);
    }

    private function snapshot(FinanceJournal $journal):array
    {
        $journal->load('lines.account');
        $lines=[];
        foreach($journal->lines->sortBy('id') as $line) {
            abort_unless($line->business_id===$journal->business_id &&
                $line->account?->business_id===$journal->business_id,422);
            $lines[]=[
                'account_id'=>$line->account_id,'system_key'=>$line->account->system_key,
                'debit'=>(string)$line->debit,'credit'=>(string)$line->credit,
                'base_debit'=>(string)$line->base_debit,'base_credit'=>(string)$line->base_credit,
            ];
        }
        return [
            'journal_id'=>$journal->id,'reference'=>$journal->reference,
            'source_type'=>$journal->source_type,'source_id'=>$journal->source_id,
            'currency'=>$journal->currency,'base_currency'=>$journal->base_currency,
            'rate_to_base'=>(string)$journal->rate_to_base,
            'posted_on'=>$journal->posted_on->toDateString(),'lines'=>$lines,
        ];
    }

    private function assertEligible(
        Business $business,FinancePayment|FinanceShipmentCost|FinancePayrollSettlement $source,
        string $type,int $excludedReversalId=0
    ):FinanceJournal {
        abort_unless($source->business_id===$business->id && $source->status==='posted',422,
            'Only a posted transaction that has not been reversed may be corrected.');
        if(FinanceBankMatch::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$source->id)
            ->whereIn('status',['pending','confirmed'])->exists()) {
            throw ValidationException::withMessages(['reversal'=>
                'An imported bank statement already has an active match for this transaction. It must be resolved through a controlled bank correction before a reversal is possible.']);
        }
        if(FinanceTransactionReversal::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$source->id)
            ->when($excludedReversalId>0,
                fn($q)=>$q->where('id','!=',$excludedReversalId))->exists()) {
            throw ValidationException::withMessages(['reversal'=>
                'This source transaction already has a correction request.']);
        }
        if($source instanceof FinancePayment) {
            $relation=$source->direction==='in'?'commercial_invoice_id':'supplier_bill_id';
            $document=$source->direction==='in'?$source->invoice:$source->bill;
            abort_unless($document && $document->business_id===$business->id,422);
            if(FinancePayment::query()->where('business_id',$business->id)
                ->where($relation,$document->id)->where('status','posted')
                ->where('id','>',$source->id)->exists()) {
                throw ValidationException::withMessages(['reversal'=>
                    'Reverse later posted instalments first to preserve foreign-exchange settlement allocation.']);
            }
            if(FinancePayment::query()->where('business_id',$business->id)
                ->where($relation,$document->id)->where('status','pending')->exists()) {
                throw ValidationException::withMessages(['reversal'=>
                    'Other payments for this document are still pending approval. Finish them before reversing this receipt.']);
            }
        }
        return FinanceJournal::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$source->id)
            ->with('lines.account')->firstOrFail();
    }

    public function prepare(Business $business,string $type,int $id,
        string $reason,string $externalEvidence,int $actor):FinanceTransactionReversal
    {
        return DB::transaction(function()use($business,$type,$id,$reason,$externalEvidence,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(FinancePeriodControls::class)->assertWritable($business,now()->toDateString());
            $source=$this->source($business->id,$type,$id);
            $original=$this->assertEligible($business,$source,$type);
            $snapshot=$this->snapshot($original);
            $snapshot['cash_account_id']=$source->cash_account_id;
            $snapshot['amount']=(string)$source->amount;
            $snapshot['external_reference']=(string)$source->external_reference;
            $snapshot['original_status']='posted';
            $snapshot['correction_reason']=$reason;
            $snapshot['correction_evidence']=$externalEvidence;
            abort_unless(count($snapshot['lines'])>=2
                && $snapshot['currency']===$source->currency
                && $source->cashAccount?->business_id===$business->id,422);
            return FinanceTransactionReversal::create([
                'business_id'=>$business->id,'reference'=>'PMREV-'.Str::upper((string)Str::ulid()),
                'source_type'=>$type,'source_id'=>$id,
                'original_journal_id'=>$original->id,'cash_account_id'=>$source->cash_account_id,
                'currency'=>$source->currency,'amount'=>$source->amount,
                'reason'=>$reason,'external_evidence'=>$externalEvidence,
                'original_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
        },3);
    }

    public function original(FinanceTransactionReversal $reversal):FinanceJournal
    {
        $business=Business::query()->findOrFail($reversal->business_id);
        $source=$this->source($business->id,$reversal->source_type,$reversal->source_id);
        $original=$this->assertEligible($business,$source,$reversal->source_type,$reversal->id);
        abort_unless($original->id===$reversal->original_journal_id,422,
            'Source journal has changed.');
        $snapshot=$this->snapshot($original);
        $snapshot['cash_account_id']=$source->cash_account_id;
        $snapshot['amount']=(string)$source->amount;
        $snapshot['external_reference']=(string)$source->external_reference;
        $snapshot['original_status']='posted';
        $snapshot['correction_reason']=$reversal->reason;
        $snapshot['correction_evidence']=$reversal->external_evidence;
        abort_unless($reversal->posting_status==='not_posted'
            && $reversal->source_id===$snapshot['source_id']
            && $reversal->source_type===$snapshot['source_type']
            && $reversal->cash_account_id===$snapshot['cash_account_id']
            && $reversal->currency===$snapshot['currency']
            && \Brick\Math\BigDecimal::of((string)$reversal->amount)->isEqualTo($snapshot['amount'])
            && hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))===$reversal->sha256
            && $reversal->original_snapshot==$snapshot,422,
            'The original finance journal or payment evidence changed after the correction was prepared.');
        return $original;
    }

    public function lines(FinanceTransactionReversal $reversal):array
    {
        $this->original($reversal);
        $ledger=app(FinanceLedger::class);
        $lines=[];
        foreach($reversal->original_snapshot['lines'] as $item) {
            $account=FinanceAccount::query()->where('business_id',$reversal->business_id)
                ->where('active',true)->findOrFail($item['account_id']);
            abort_unless($account->system_key===$item['system_key'],422,
                'The original chart account changed after the reversal was proposed.');
            $lines[]=$ledger->line(
                $item['system_key'],$item['credit'],$item['debit'],
                $item['base_credit'],$item['base_debit'],
                'Exact correction reversing the original approved journal'
            );
        }
        return $lines;
    }

    public function finalize(FinanceTransactionReversal $reversal):void
    {
        $this->lines($reversal);
        $source=$this->source($reversal->business_id,$reversal->source_type,$reversal->source_id);
        $source->update(['status'=>'reversed']);
        $reversal->update(['posting_status'=>'posted','posted_at'=>now()]);
    }
}
