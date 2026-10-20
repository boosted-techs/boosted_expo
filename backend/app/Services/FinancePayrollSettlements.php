<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceJournal;
use App\Models\FinancePayrollLine;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePayrollSettlements
{
    private function totalUsed(FinancePayrollRun $run,string $kind,?int $lineId=null,
        ?int $excludeId=null):BigDecimal
    {
        $query=FinancePayrollSettlement::query()->where('business_id',$run->business_id)
            ->where('payroll_run_id',$run->id)->where('kind',$kind)
            ->whereIn('status',['pending','posted']);
        if($kind==='salary') $query->where('payroll_line_id',$lineId);
        if($excludeId!==null) $query->where('id','!=',$excludeId);
        $total=BigDecimal::zero();
        foreach($query->pluck('amount') as $item) $total=$total->plus((string)$item);
        return $total;
    }

    private function limit(FinancePayrollRun $run,string $kind,?int $lineId=null):BigDecimal
    {
        if($kind==='withholding') return BigDecimal::of((string)$run->deduction_total);
        $line=FinancePayrollLine::query()->where('business_id',$run->business_id)
            ->where('payroll_run_id',$run->id)->findOrFail($lineId);
        return BigDecimal::of((string)$line->net);
    }

    public function remaining(FinancePayrollRun $run,string $kind,?int $lineId=null):BigDecimal
    {
        return $this->limit($run,$kind,$lineId)
            ->minus($this->totalUsed($run,$kind,$lineId));
    }

    public function prepare(Business $business,FinancePayrollRun $run,array $data,int $actor)
        :FinancePayrollSettlement
    {
        return DB::transaction(function()use($business,$run,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing=FinancePayrollSettlement::query()->where('business_id',$business->id)
                ->where('idempotency_key',$data['idempotency_key'])->first();
            if($existing) {
                $snapshot=$existing->data_snapshot??[];
                $replay=$existing->payroll_run_id===$run->id
                    && ($snapshot['kind']??null)===$data['kind']
                    && ($snapshot['payroll_line_id']??null)===
                        ($data['kind']==='salary'?(int)($data['payroll_line_id']??0):null)
                    && ($snapshot['recipient_name']??null)===
                        ($data['kind']==='withholding'?trim($data['recipient_name']??''):null)
                    && isset($snapshot['amount'])
                    && BigDecimal::of((string)$snapshot['amount'])->isEqualTo((string)$data['amount'])
                    && ($snapshot['paid_on']??null)===$data['paid_on']
                    && ($snapshot['external_reference']??null)===$data['external_reference']
                    && ($snapshot['evidence_note']??null)===$data['evidence_note']
                    && ($snapshot['payment_method']??null)===$data['cash_account_key']
                    && ($snapshot['bank_profile_id']??null)===
                        (isset($data['bank_profile_id'])?(int)$data['bank_profile_id']:null);
                if(!$replay) throw ValidationException::withMessages([
                    'idempotency_key'=>
                        'This payment request key has already been used with different employee, account or amount details.',
                ]);
                return $existing;
            }
            $run=FinancePayrollRun::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($run->id);
            abort_unless($run->posting_status==='posted',422,
                'Approve and post the payroll accrual before recording a settlement.');
            if(\App\Models\FinancePayrollReversal::query()->where('business_id',$business->id)
                ->where('payroll_run_id',$run->id)
                ->where('posting_status','!=','withdrawn')->exists()) {
                throw ValidationException::withMessages(['settlement'=>
                    'This payroll has a correction request. It cannot accept new settlements until resolved.']);
            }
            FinanceJournal::query()->where('business_id',$business->id)
                ->where('source_type','payroll_run')->where('source_id',$run->id)->firstOrFail();
            $kind=$data['kind'];
            abort_unless(in_array($kind,['salary','withholding'],true),422);
            $lineId=$kind==='salary'?(int)($data['payroll_line_id']??0):null;
            $recipient=$kind==='withholding'?trim($data['recipient_name']??''):null;
            if($kind==='withholding' && mb_strlen($recipient)<3) {
                throw ValidationException::withMessages(['recipient_name'=>
                    'Record the actual receiving authority or organisation for manual deductions.']);
            }
            $amount=app(FinanceMath::class)->money((string)$data['amount']);
            if($amount->isLessThanOrEqualTo(0) ||
                $amount->isGreaterThan($this->remaining($run,$kind,$lineId))) {
                throw ValidationException::withMessages(['amount'=>
                    'The settlement exceeds the unpaid payroll amount, including pending payments.']);
            }
            if($data['paid_on']<$run->period_to->toDateString()) {
                throw ValidationException::withMessages(['paid_on'=>
                    'The recorded settlement date cannot precede the end of this payroll period.']);
            }
            app(FinancePeriodControls::class)->assertWritable($business,$data['paid_on']);
            $profileId=isset($data['bank_profile_id'])?(int)$data['bank_profile_id']:null;
            if($profileId!==null && $data['cash_account_key']==='cash') {
                throw ValidationException::withMessages(['bank_profile_id'=>
                    'Select bank/electronic payment when using a named bank account.']);
            }
            $account=app(FinanceBankProfiles::class)->account($business,$profileId,
                $data['cash_account_key'],$run->currency);
            $snapshot=[
                'payroll_run_id'=>$run->id,'payroll_reference'=>$run->reference,
                'payroll_sha256'=>$run->sha256,'payroll_line_id'=>$lineId,
                'kind'=>$kind,'recipient_name'=>$recipient,'currency'=>$run->currency,
                'amount'=>(string)$amount,'cash_account_id'=>$account->id,
                'cash_account_key'=>$account->system_key,
                'payment_method'=>$data['cash_account_key'],
                'bank_profile_id'=>$profileId,'paid_on'=>$data['paid_on'],
                'external_reference'=>$data['external_reference'],
                'evidence_note'=>$data['evidence_note'],
            ];
            return FinancePayrollSettlement::create([
                'business_id'=>$business->id,'payroll_run_id'=>$run->id,
                'payroll_line_id'=>$lineId,'cash_account_id'=>$account->id,
                'reference'=>'PS-'.Str::upper((string)Str::ulid()),'kind'=>$kind,
                'recipient_name'=>$recipient,'currency'=>$run->currency,
                'amount'=>(string)$amount,'paid_on'=>$data['paid_on'],
                'external_reference'=>$data['external_reference'],
                'evidence_note'=>$data['evidence_note'],'status'=>'pending',
                'idempotency_key'=>$data['idempotency_key'],'created_by'=>$actor,
                'data_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
            ]);
        },3);
    }

    public function lines(FinancePayrollSettlement $source):array
    {
        $run=FinancePayrollRun::query()->where('business_id',$source->business_id)
            ->findOrFail($source->payroll_run_id);
        abort_unless($run->posting_status==='posted',422,
            'The payroll accrual has been reversed or was never posted.');
        abort_unless(!\App\Models\FinancePayrollReversal::query()->where('business_id',$run->business_id)
            ->where('payroll_run_id',$run->id)
            ->where('posting_status','!=','withdrawn')->exists(),422,
            'This payroll has a correction request and cannot settle.');
        FinanceJournal::query()->where('business_id',$run->business_id)
            ->where('source_type','payroll_run')->where('source_id',$run->id)->firstOrFail();
        $snapshot=$source->data_snapshot;
        abort_unless(is_array($snapshot) &&
            hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))===$source->sha256 &&
            $run->sha256===$snapshot['payroll_sha256'] &&
            $run->reference===$snapshot['payroll_reference'] &&
            $source->payroll_run_id===$snapshot['payroll_run_id'] &&
            $source->payroll_line_id===$snapshot['payroll_line_id'] &&
            $source->kind===$snapshot['kind'] &&
            $source->recipient_name===$snapshot['recipient_name'] &&
            $source->currency===$snapshot['currency'] &&
            $source->cash_account_id===$snapshot['cash_account_id'] &&
            $source->paid_on->toDateString()===$snapshot['paid_on'] &&
            $source->external_reference===$snapshot['external_reference'] &&
            $source->evidence_note===$snapshot['evidence_note'] &&
            BigDecimal::of((string)$source->amount)->isEqualTo($snapshot['amount']),
            422,'This payroll settlement no longer matches its approved evidence snapshot.');
        $account=$source->cashAccount;
        abort_unless($account && $account->business_id===$source->business_id &&
            $account->system_key===$snapshot['cash_account_key'],422,
            'The cash or bank ledger has changed.');
        app(FinanceBankProfiles::class)->assertPaymentAccount($account,$run->currency);
        $amount=BigDecimal::of((string)$source->amount);
        $max=$this->limit($run,$source->kind,$source->payroll_line_id);
        $used=$this->totalUsed($run,$source->kind,$source->payroll_line_id,$source->id);
        abort_unless($used->plus($amount)->isLessThanOrEqualTo($max),422,
            'Payroll amount is no longer available for this settlement.');
        $ledger=app(FinanceLedger::class);
        $reason=$source->kind==='salary'?'Recorded salary settlement':'Recorded manual deduction remittance';
        return [
            $ledger->line($source->kind==='salary'?'payroll_payable':'payroll_withholding',
                (string)$amount,'0',(string)$amount,'0',$reason),
            $ledger->line($account->system_key,'0',(string)$amount,'0',(string)$amount,
                $source->external_reference),
        ];
    }
}
