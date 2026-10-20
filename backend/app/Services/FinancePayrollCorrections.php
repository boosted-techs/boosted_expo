<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePostingRequest;
use App\Models\FinancePayrollCorrectionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePayrollCorrections
{
    public function assertReversible(FinancePayrollRun $run):FinanceJournal
    {
        abort_unless($run->posting_status==='posted',422,
            'Only a posted, unreversed payroll accrual may be corrected.');
        if(FinancePayrollSettlement::query()->where('business_id',$run->business_id)
            ->where('payroll_run_id',$run->id)
            ->whereIn('status',['pending','posted'])->exists()) {
            throw ValidationException::withMessages(['correction'=>
                'This payroll has pending or posted payments. Resolve settlements using a separately controlled process before reversing the accrual.']);
        }
        return FinanceJournal::query()->where('business_id',$run->business_id)
            ->where('source_type','payroll_run')->where('source_id',$run->id)
            ->with('lines.account')->firstOrFail();
    }

    private function sourceSnapshot(FinanceJournal $journal):array
    {
        $items=[];
        foreach($journal->lines->sortBy('id') as $line) {
            abort_unless($line->business_id===$journal->business_id
                && $line->account?->business_id===$journal->business_id,422);
            $items[]=[
                'account_id'=>$line->account_id,
                'system_key'=>$line->account->system_key,
                'debit'=>$line->debit,'credit'=>$line->credit,
                'base_debit'=>$line->base_debit,'base_credit'=>$line->base_credit,
            ];
        }
        return [
            'source_journal_id'=>$journal->id,
            'source_type'=>$journal->source_type,'source_id'=>$journal->source_id,
            'currency'=>$journal->currency,'base_currency'=>$journal->base_currency,
            'rate_to_base'=>$journal->rate_to_base,
            'posted_on'=>$journal->posted_on->toDateString(),
            'lines'=>$items,
        ];
    }

    private function event(FinancePayrollReversal $correction,int $actor,
        string $action,string $reason,?array $snapshot=null):void
    {
        FinancePayrollCorrectionEvent::create([
            'business_id'=>$correction->business_id,'payroll_reversal_id'=>$correction->id,
            'actor_id'=>$actor,'action'=>$action,'reason'=>$reason,
            'snapshot'=>$snapshot,'created_at'=>now(),
        ]);
    }

    public function prepare(Business $business,FinancePayrollRun $run,
        string $reason,int $actor):FinancePayrollReversal
    {
        return DB::transaction(function()use($business,$run,$reason,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $run=FinancePayrollRun::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($run->id);
            $prior=FinancePayrollReversal::query()->where('business_id',$business->id)
                ->where('payroll_run_id',$run->id)->lockForUpdate()->first();
            if($prior && $prior->posting_status!=='withdrawn') {
                throw ValidationException::withMessages(['correction'=>
                    'An active or posted correction already exists for this payroll.']);
            }
            app(FinancePeriodControls::class)->assertWritable($business,now()->toDateString());
            $original=$this->assertReversible($run);
            $snapshot=$this->sourceSnapshot($original);
            abort_unless($snapshot['currency']===$business->base_currency
                && $snapshot['source_id']===$run->id && count($snapshot['lines'])>=2,422);
            if($prior) {
                abort_unless($prior->original_journal_id===$original->id
                    && $prior->sha256===hash('sha256',
                        json_encode($snapshot,JSON_THROW_ON_ERROR)),422,
                    'An earlier correction snapshot no longer matches the original journal.');
                $this->event($prior,$actor,'resumed',$reason,[
                    'previous_reason'=>$prior->reason,'original_sha256'=>$prior->sha256,
                ]);
                $prior->update(['posting_status'=>'not_posted','reason'=>$reason]);
                return $prior;
            }
            $correction=FinancePayrollReversal::create([
                'business_id'=>$business->id,'payroll_run_id'=>$run->id,
                'original_journal_id'=>$original->id,
                'reference'=>'REV-'.Str::upper((string)Str::ulid()),
                'currency'=>$run->currency,'reason'=>$reason,
                'original_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
            $this->event($correction,$actor,'prepared',$reason,['original_sha256'=>$correction->sha256]);
            return $correction;
        },3);
    }

    public function withdraw(FinancePayrollReversal $correction,int $actor,string $reason):void
    {
        DB::transaction(function()use($correction,$actor,$reason){
            $business=Business::query()->whereKey($correction->business_id)
                ->lockForUpdate()->firstOrFail();
            $correction=FinancePayrollReversal::query()
                ->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($correction->id);
            abort_unless($correction->posting_status==='not_posted',422,
                'Only a correction not yet posted may be withdrawn.');
            $review=FinancePostingRequest::query()->where('business_id',$business->id)
                ->where('source_type','payroll_reversal')->where('source_id',$correction->id)
                ->lockForUpdate()->first();
            abort_unless(!$review || $review->status==='rejected',422,
                'The independent posting review must be rejected before this correction can be withdrawn.');
            $this->event($correction,$actor,'withdrawn',$reason,[
                'original_sha256'=>$correction->sha256,
                'correction_reason'=>$correction->reason,
            ]);
            $correction->update(['posting_status'=>'withdrawn']);
        },3);
    }

    public function lines(FinancePayrollReversal $reversal):array
    {
        abort_unless($reversal->posting_status==='not_posted',422,
            'This correction is withdrawn or has already been posted.');
        $business=Business::query()->findOrFail($reversal->business_id);
        $run=FinancePayrollRun::query()->where('business_id',$business->id)
            ->findOrFail($reversal->payroll_run_id);
        $original=$this->assertReversible($run);
        abort_unless($original->id===$reversal->original_journal_id,422,
            'Payroll journal association has changed.');
        $snapshot=$reversal->original_snapshot;
        abort_unless(is_array($snapshot) &&
            hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))===$reversal->sha256 &&
            $snapshot==$this->sourceSnapshot($original) &&
            $snapshot['currency']===$reversal->currency &&
            $snapshot['base_currency']===$business->base_currency &&
            $snapshot['source_type']==='payroll_run' &&
            $snapshot['source_id']===$run->id,
            422,'The original journal no longer matches the approved correction snapshot.');
        $lines=[];
        $ledger=app(FinanceLedger::class);
        foreach($snapshot['lines'] as $source) {
            $account=FinanceAccount::query()->where('business_id',$business->id)
                ->where('active',true)->findOrFail($source['account_id']);
            abort_unless($account->system_key===$source['system_key'],422,
                'The original journal account changed.');
            $lines[]=$ledger->line(
                $source['system_key'],$source['credit'],$source['debit'],
                $source['base_credit'],$source['base_debit'],
                'Exact reversal of journal '.$original->reference,
            );
        }
        return $lines;
    }

    public function finalize(FinancePayrollReversal $reversal,int $actor):void
    {
        $this->lines($reversal);
        $run=FinancePayrollRun::query()->where('business_id',$reversal->business_id)
            ->lockForUpdate()->findOrFail($reversal->payroll_run_id);
        $run->update(['posting_status'=>'reversed']);
        $reversal->update(['posting_status'=>'posted','posted_at'=>now()]);
        $this->event($reversal,$actor,'posted',
            'Independent correction journal posted.',[
                'original_journal_id'=>$reversal->original_journal_id,
                'original_sha256'=>$reversal->sha256,
            ]);
    }
}
