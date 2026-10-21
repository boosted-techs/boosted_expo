<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceJournal;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTransactionReversal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePartialBankReturns
{
    private const SOURCES=[
        'payment'=>FinancePayment::class,
        'shipment_cost'=>FinanceShipmentCost::class,
    ];

    private function source(int $businessId,string $type,int $id)
        :FinancePayment|FinanceShipmentCost
    {
        abort_unless(isset(self::SOURCES[$type]),404);
        return self::SOURCES[$type]::query()->where('business_id',$businessId)
            ->lockForUpdate()->findOrFail($id);
    }

    private function portion(string $value,BigDecimal $total,BigDecimal $original):BigDecimal
    {
        $money=BigDecimal::of($value);
        if($total->isEqualTo($original)) return $money;
        return app(FinanceMath::class)->money(
            $money->multipliedBy($total)->dividedBy($original,14,RoundingMode::HalfUp));
    }

    private function basis(Business $business,string $type,int $id,
        int $returnLineId,?int $excluding=null,
        ?string $reason=null,?string $externalEvidence=null):array
    {
        $source=$this->source($business->id,$type,$id);
        abort_unless($source->status==='posted',422,
            'The original bank transaction must remain posted and unreversed.');
        if(FinanceTransactionReversal::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$id)->exists()) {
            throw ValidationException::withMessages(['return_bank_line_id'=>
                'An existing full correction prohibits independent partial returns.']);
        }
        $line=FinanceBankStatementLine::query()->where('business_id',$business->id)
            ->findOrFail($returnLineId);
        $amount=BigDecimal::of((string)$line->amount);
        $originalAmount=BigDecimal::of((string)$source->amount);
        if($amount->isLessThanOrEqualTo(0)
            || $amount->isGreaterThanOrEqualTo($originalAmount)) {
            throw ValidationException::withMessages(['return_bank_line_id'=>
                'A partial return must be strictly smaller than the original payment. For a full return use the existing complete-correction workflow.']);
        }
        $evidence=app(FinanceBankReturns::class)->evidence($business,$type,$id,
            $source->cash_account_id,$source->currency,(string)$originalAmount,
            $returnLineId,null,true,$excluding);
        $prior=FinancePartialBankReturn::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$id)
            ->when($excluding!==null,fn($q)=>$q->where('id','!=',$excluding))->get();
        foreach($prior as $row) {
            if($row->posting_status!=='posted') throw ValidationException::withMessages([
                'return_bank_line_id'=>
                    'Another return for this payment awaits independent approval. Post it first.',
            ]);
        }
        $used=BigDecimal::zero();
        foreach($prior as $row) $used=$used->plus((string)$row->amount);
        if($used->plus($amount)->isGreaterThan($originalAmount)) {
            throw ValidationException::withMessages(['return_bank_line_id'=>
                'Partial bank returns exceed the original posted payment after earlier returns.']);
        }
        $original=FinanceJournal::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$id)
            ->with('lines.account')->firstOrFail();
        $snapshotRows=[];$lines=[];$debit=BigDecimal::zero();$credit=BigDecimal::zero();
        $total=$used->plus($amount);
        foreach($original->lines->sortBy('id') as $journalLine) {
            $account=$journalLine->account;
            abort_unless($journalLine->business_id===$business->id
                && $account?->business_id===$business->id
                && $account->active,422,
                'Original payment journal account is not company-owned and active.');
            $row=[
                'line_id'=>$journalLine->id,'account_id'=>$journalLine->account_id,
                'account'=>$account->system_key,
                'debit'=>(string)$journalLine->debit,'credit'=>(string)$journalLine->credit,
                'base_debit'=>(string)$journalLine->base_debit,
                'base_credit'=>(string)$journalLine->base_credit,
            ];
            $snapshotRows[]=$row;
            $values=[];
            foreach(['debit','credit','base_debit','base_credit'] as $field) {
                $values[$field]=$this->portion($row[$field],$total,$originalAmount)
                    ->minus($this->portion($row[$field],$used,$originalAmount));
            }
            // Reversing a portion swaps each originally approved debit/credit.
            $item=app(FinanceLedger::class)->line($row['account'],
                (string)$values['credit']->toScale(2),
                (string)$values['debit']->toScale(2),
                (string)$values['base_credit']->toScale(2),
                (string)$values['base_debit']->toScale(2),
                'Bank-confirmed portion returned against '.$original->reference);
            $lines[]=$item;
            $debit=$debit->plus((string)$item['base_debit']);
            $credit=$credit->plus((string)$item['base_credit']);
        }
        // Fractional currencies may round each source line independently.
        // Preserve proportional bank and receivable/payable valuations, and
        // explicitly record any penny-scale balancing difference in realised FX.
        $difference=$debit->minus($credit);
        if(!$difference->isZero()) {
            $key=$difference->isGreaterThan(0)?'fx_gain':'fx_loss';
            $round=app(FinanceMath::class)->money($difference->abs());
            $lines[]=app(FinanceLedger::class)->line($key,'0','0',
                $difference->isLessThan(0)?(string)$round:'0',
                $difference->isGreaterThan(0)?(string)$round:'0',
                'Explicit base-currency rounding difference on partial bank return');
        }
        $returnDate=$line->transaction_on->toDateString();
        app(FinancePeriodControls::class)->assertWritable($business,$returnDate);
        $snapshot=[
            'source_type'=>$type,'source_id'=>$source->id,
            'original_journal_id'=>$original->id,'original_journal_reference'=>$original->reference,
            'original_rate'=>(string)$original->rate_to_base,
            'original_bank_source_reference'=>$source->external_reference,
            'cash_account_id'=>$source->cash_account_id,
            'currency'=>$source->currency,'original_amount'=>(string)$originalAmount,
            'used_before'=>(string)$used->toScale(2),
            'new_total'=>(string)$total->toScale(2),
            'return_amount'=>(string)$amount->toScale(2),
            'bank_evidence'=>$evidence,
            'return_date'=>$returnDate,
            'original_lines'=>$snapshotRows,'reversing_lines'=>$lines,
            'reason'=>$reason,'external_evidence'=>$externalEvidence,
        ];
        return ['snapshot'=>$snapshot,'lines'=>$lines,
            'amount'=>$amount,'original'=>$original,'bank'=>$evidence];
    }

    public function prepare(Business $business,string $type,int $id,int $returnLineId,
        string $reason,string $externalEvidence,int $actor):FinancePartialBankReturn
    {
        return DB::transaction(function()use($business,$type,$id,$returnLineId,
            $reason,$externalEvidence,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $result=$this->basis($business,$type,$id,$returnLineId,null,$reason,$externalEvidence);
            $data=$result['snapshot'];
            return FinancePartialBankReturn::create([
                'business_id'=>$business->id,
                'reference'=>'PARTRET-'.Str::upper((string)Str::ulid()),
                'source_type'=>$type,'source_id'=>$id,
                'original_journal_id'=>$result['original']->id,
                'original_bank_match_id'=>$result['bank']['original_match_id'],
                'return_bank_line_id'=>$returnLineId,
                'cash_account_id'=>$data['cash_account_id'],
                'currency'=>$data['currency'],'amount'=>(string)$result['amount']->toScale(2),
                'reason'=>$reason,'external_evidence'=>$externalEvidence,
                'data_snapshot'=>$data,
                'sha256'=>hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
        },3);
    }

    public function lines(FinancePartialBankReturn $source):array
    {
        abort_unless($source->posting_status==='not_posted',422,
            'Only a pending partial bank return can be posted.');
        $business=Business::query()->findOrFail($source->business_id);
        $result=$this->basis($business,$source->source_type,$source->source_id,
            $source->return_bank_line_id,$source->id,
            $source->reason,$source->external_evidence);
        $data=$result['snapshot'];
        abort_unless($source->original_journal_id===$data['original_journal_id']
            && $source->original_bank_match_id===$data['bank_evidence']['original_match_id']
            && $source->cash_account_id===$data['cash_account_id']
            && $source->currency===$data['currency']
            && BigDecimal::of((string)$source->amount)->isEqualTo($data['return_amount'])
            && $source->data_snapshot==$data
            && hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))===$source->sha256,422,
            'Partial bank return evidence, other partial returns or original journal changed after preparation.');
        return $result['lines'];
    }
}
