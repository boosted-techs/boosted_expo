<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceOpeningBalance;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePayrollReversal;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankProfile;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinancePeriodEvent;
use App\Models\FinancePostingRequest;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePeriodControls
{
    public function assertWritable(Business $business,string $date):void
    {
        if(FinancePeriod::query()->where('business_id',$business->id)
            ->whereIn('status',['pending','closed'])
            ->whereDate('period_to','>=',$date)->exists()) {
            throw ValidationException::withMessages(['posting_on'=>
                'This posting date is locked by a pending or closed financial period.']);
        }
    }

    public function create(Business $business,string $from,string $to,int $actor):FinancePeriod
    {
        return DB::transaction(function()use($business,$from,$to,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if($from>$to || $to>now()->toDateString() ||
                substr($from,0,7)!==substr($to,0,7)) {
                throw ValidationException::withMessages(['period_to'=>
                    'Select dates within one calendar month, ending no later than today.']);
            }
            if(FinancePeriod::query()->where('business_id',$business->id)
                ->where('status','closed')->whereDate('period_to','>=',$from)->exists()) {
                throw ValidationException::withMessages(['period_from'=>
                    'You cannot create a new period before or within a period that was already closed.']);
            }
            if(FinancePeriod::query()->where('business_id',$business->id)
                ->whereDate('period_from','<=',$to)
                ->whereDate('period_to','>=',$from)->exists()) {
                throw ValidationException::withMessages(['period_from'=>'This accounting period overlaps an existing period.']);
            }
            return FinancePeriod::create([
                'business_id'=>$business->id,'period_from'=>$from,'period_to'=>$to,
                'created_by'=>$actor,'status'=>'open',
            ]);
        },3);
    }

    private function snapshot(Business $business,string $through):array
    {
        $totals=['debit'=>BigDecimal::zero(),'credit'=>BigDecimal::zero()];
        foreach(FinanceJournalLine::query()->where('finance_journal_lines.business_id',$business->id)
            ->join('finance_journals as j',function($j){
                $j->on('j.id','=','finance_journal_lines.journal_id')
                    ->on('j.business_id','=','finance_journal_lines.business_id');
            })->whereDate('j.posted_on','<=',$through)
            ->get(['finance_journal_lines.base_debit','finance_journal_lines.base_credit']) as $line) {
            $totals['debit']=$totals['debit']->plus((string)$line->base_debit);
            $totals['credit']=$totals['credit']->plus((string)$line->base_credit);
        }
        if(!$totals['debit']->isEqualTo($totals['credit'])) {
            throw ValidationException::withMessages(['period'=>
                'The trial balance does not balance in the company base currency.']);
        }
        return [
            'through'=>$through,'business_id'=>$business->id,
            'journal_count'=>FinanceJournal::query()->where('business_id',$business->id)
                ->whereDate('posted_on','<=',$through)->count(),
            'total_base_debits'=>(string)$totals['debit']->toScale(2),
            'total_base_credits'=>(string)$totals['credit']->toScale(2),
            'account_count'=>\App\Models\FinanceAccount::query()
                ->where('business_id',$business->id)->count(),
        ];
    }

    private function outstanding(Business $business,string $through):void
    {
        $b=$business->id;
        $sources=[
            [CommercialInvoice::class,'posting_status','not_posted','issued_at'],
            [FinanceSupplierBill::class,'posting_status','not_posted','vendor_invoice_date'],
            [FinancePayment::class,'status','pending','payment_on'],
            [FinanceShipmentCost::class,'status','pending','paid_on'],
            [FinanceCreditNote::class,'posting_status','not_posted','created_at'],
            [FinanceInventoryRelief::class,'posting_status','not_posted','created_at'],
            [FinancePayrollRun::class,'posting_status','not_posted','period_to'],
            [FinancePayrollSettlement::class,'status','pending','paid_on'],
            [FinancePayrollReversal::class,'posting_status','not_posted','created_at'],
            [FinanceTransactionReversal::class,'posting_status','not_posted','created_at'],
            [FinanceForeignBankOpening::class,'posting_status','not_posted','effective_on'],
            [FinanceBalanceAdjustment::class,'posting_status','not_posted','created_at'],
            [FinanceOpeningBalance::class,'posting_status','not_posted','effective_on'],
        ];
        foreach($sources as [$class,$column,$status,$date]) {
            if($class::query()->where('business_id',$b)
                ->where($column,$status)->whereDate($date,'<=',$through)->exists()) {
                throw ValidationException::withMessages(['period'=>
                    'There are unposted '.strtolower(class_basename($class)).
                    ' records on or before this closing date. Complete or correct them first.']);
            }
        }
        if(FinancePostingRequest::query()->where('business_id',$b)
            ->whereIn('status',['pending','approved'])
            ->whereDate('posting_on','<=',$through)->exists()) {
            throw ValidationException::withMessages(['period'=>
                'Finish the pending financial posting approvals before closing this period.']);
        }
        if(FinanceBankStatement::query()->where('business_id',$b)
            ->where('status','open')->whereDate('period_to','<=',$through)->exists()) {
            throw ValidationException::withMessages(['period'=>
                'Reconcile imported bank statements ending on or before this closing date.']);
        }
        // New named, single-currency bank ledgers additionally need independent
        // book-balance proof. Legacy shared-currency bank data still requires
        // historical reconstruction and cannot be silently declared verified.
        $namedAccounts=FinanceBankProfile::query()->where('business_id',$b)
            ->pluck('account_id')->all();
        if($namedAccounts!==[]) {
            foreach(FinanceBankStatement::query()->where('business_id',$b)
                ->whereIn('account_id',$namedAccounts)
                ->whereDate('period_to','<=',$through)->get() as $statement) {
                $check=FinanceBankLedgerCheck::query()->where('business_id',$b)
                    ->where('statement_id',$statement->id)->first();
                if(!$check || !app(FinanceBankLedgerProofs::class)->isCurrent($check)) {
                    throw ValidationException::withMessages(['period'=>
                        'A named-bank statement is not yet independently verified against its ledger, or its evidence became stale. Complete bank-to-ledger proof before closing the period.']);
                }
            }
        }
    }

    private function event(FinancePeriod $period,int $actor,string $action,
        string $reason,?array $snapshot=null):void
    {
        FinancePeriodEvent::create([
            'business_id'=>$period->business_id,'period_id'=>$period->id,
            'actor_id'=>$actor,'action'=>$action,'reason'=>$reason,
            'snapshot'=>$snapshot,'created_at'=>now(),
        ]);
    }

    public function submit(FinancePeriod $period,int $actor,string $reason):FinancePeriod
    {
        return DB::transaction(function()use($period,$actor,$reason){
            $business=Business::query()->whereKey($period->business_id)
                ->lockForUpdate()->firstOrFail();
            $period=FinancePeriod::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($period->id);
            abort_unless($period->status==='open',422,'Only open periods may be submitted for closing.');
            abort_unless(!FinancePeriod::query()->where('business_id',$business->id)
                ->where('id','!=',$period->id)->where('status','open')
                ->whereDate('period_to','<',$period->period_from->toDateString())->exists(),422,
                'Close earlier open financial periods first.');
            $this->outstanding($business,$period->period_to->toDateString());
            $snapshot=$this->snapshot($business,$period->period_to->toDateString());
            $period->update([
                'status'=>'pending','requested_by'=>$actor,
                'reason'=>$reason,'close_snapshot'=>$snapshot,
                'close_hash'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
            ]);
            $this->event($period,$actor,'submitted',$reason,$snapshot);
            return $period;
        },3);
    }

    public function review(FinancePeriod $period,bool $approve,int $actor,string $reason):FinancePeriod
    {
        return DB::transaction(function()use($period,$approve,$actor,$reason){
            $business=Business::query()->whereKey($period->business_id)
                ->lockForUpdate()->firstOrFail();
            $period=FinancePeriod::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($period->id);
            abort_unless($period->status==='pending',422,'The close request is no longer pending.');
            abort_unless($period->requested_by!==$actor,422,
                'An independent owner or administrator must review the period closure.');
            if($approve) {
                $this->outstanding($business,$period->period_to->toDateString());
                $snapshot=$this->snapshot($business,$period->period_to->toDateString());
                abort_unless(hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))
                    ===$period->close_hash,422,
                    'Journal balances or chart details changed after the closing request.');
                $period->update(['status'=>'closed','reviewed_by'=>$actor,'closed_at'=>now()]);
            } else {
                $period->update([
                    'status'=>'open','requested_by'=>null,
                    'close_snapshot'=>null,'close_hash'=>null,'closed_at'=>null,
                    'reviewed_by'=>$actor,
                ]);
            }
            $this->event($period,$actor,$approve?'closed':'rejected',$reason,
                $approve?$period->close_snapshot:null);
            return $period;
        },3);
    }
}
