<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceJournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceFxRevaluations
{
    /**
     * Reads both the foreign units and the accumulated book valuation,
     * including earlier revaluations (whose foreign units are zero).
     */
    private function basis(Business $business,int $profileId,int $rateId,string $date):array
    {
        $profile=FinanceBankProfile::query()->where('business_id',$business->id)
            ->where('active',true)->with('account')->findOrFail($profileId);
        abort_unless($profile->currency!==$business->base_currency
            && $profile->account?->active
            && $profile->account->business_id===$business->id
            && $profile->account->type==='asset',422,
            'Choose an active, company-owned foreign-currency asset bank ledger.');
        $rate=FinanceExchangeRate::query()->where('business_id',$business->id)
            ->where('status','approved')->findOrFail($rateId);
        $fx=app(FinanceRates::class)->rate($business,$profile->currency,$rateId,$date);
        $units=BigDecimal::zero();$carrying=BigDecimal::zero();
        $details=[];
        $q=FinanceJournalLine::query()->where('finance_journal_lines.business_id',$business->id)
            ->where('finance_journal_lines.account_id',$profile->account_id)
            ->join('finance_journals as j',function($join){
                $join->on('j.id','=','finance_journal_lines.journal_id')
                    ->on('j.business_id','=','finance_journal_lines.business_id');
            })->orderBy('j.posted_on')->orderBy('j.id')
            ->orderBy('finance_journal_lines.id');
        foreach($q->get([
            'finance_journal_lines.id','finance_journal_lines.debit','finance_journal_lines.credit',
            'finance_journal_lines.base_debit','finance_journal_lines.base_credit',
            'j.id as journal_id','j.posted_on','j.currency',
        ]) as $line) {
            $day=substr((string)$line->posted_on,0,10);
            if($day>$date) throw ValidationException::withMessages(['valued_on'=>
                'Revalue only at or after the latest posted bank-ledger movement. Earlier revaluations require a separate correction process.']);
            if($line->currency!==$profile->currency) throw ValidationException::withMessages([
                'bank_profile_id'=>'This bank ledger contains an incompatible currency journal. Resolve it first.',
            ]);
            $units=$units->plus((string)$line->debit)->minus((string)$line->credit);
            $carrying=$carrying->plus((string)$line->base_debit)
                ->minus((string)$line->base_credit);
            $details[]=[
                'line_id'=>$line->id,'journal_id'=>$line->journal_id,'posted_on'=>$day,
                'debit'=>(string)$line->debit,'credit'=>(string)$line->credit,
                'base_debit'=>(string)$line->base_debit,
                'base_credit'=>(string)$line->base_credit,
            ];
        }
        if($units->isLessThanOrEqualTo(0)) throw ValidationException::withMessages([
            'bank_profile_id'=>
                'This flow revalues positive foreign bank assets only. Nil balances and overdrafts need a separately approved accounting treatment.',
        ]);
        $target=app(FinanceMath::class)->base($units,$fx);
        $delta=$target->minus($carrying);
        if($delta->isZero()) throw ValidationException::withMessages([
            'exchange_rate_id'=>'There is no difference between the approved valuation and the current book value.',
        ]);
        return [
            'bank_profile_id'=>$profile->id,'account_id'=>$profile->account_id,
            'account_key'=>$profile->account->system_key,
            'currency'=>$profile->currency,'base_currency'=>$business->base_currency,
            'valued_on'=>$date,'exchange_rate_id'=>$rate->id,
            'rate_to_base'=>(string)$fx,
            'rate_source_note'=>$rate->source_note,
            'foreign_balance'=>(string)$units->toScale(2),
            'previous_base'=>(string)$carrying->toScale(2),
            'revalued_base'=>(string)$target->toScale(2),
            'change_base'=>(string)$delta->toScale(2),
            'journal_fingerprint'=>hash('sha256',
                json_encode($details,JSON_THROW_ON_ERROR)),
        ];
    }

    public function prepare(Business $business,array $data,int $actor):FinanceFxRevaluation
    {
        return DB::transaction(function()use($business,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $date=$data['valued_on'];
            app(FinancePeriodControls::class)->assertWritable($business,$date);
            if(\App\Models\FinanceAccount::query()->where('business_id',$business->id)
                ->where('active',true)->whereIn('system_key',[
                    'unrealized_fx_gain','unrealized_fx_loss'
                ])->count()!==2) {
                throw ValidationException::withMessages(['bank_profile_id'=>
                    'Ask the business owner to activate the two missing unrealised FX accounts from the Finance Dashboard. Existing journals remain unchanged.']);
            }
            if(FinanceFxRevaluation::query()->where('business_id',$business->id)
                ->where('bank_profile_id',$data['bank_profile_id'])
                ->whereDate('valued_on',$date)->exists()) {
                throw ValidationException::withMessages(['valued_on'=>
                    'A bank revaluation for this date is already prepared or posted.']);
            }
            $snapshot=$this->basis($business,(int)$data['bank_profile_id'],
                (int)$data['exchange_rate_id'],$date);
            $snapshot['valuation_evidence']=$data['valuation_evidence'];
            return FinanceFxRevaluation::create([
                'business_id'=>$business->id,'bank_profile_id'=>$snapshot['bank_profile_id'],
                'account_id'=>$snapshot['account_id'],
                'exchange_rate_id'=>$snapshot['exchange_rate_id'],
                'reference'=>'FXVAL-'.Str::upper((string)Str::ulid()),
                'currency'=>$snapshot['currency'],'valued_on'=>$date,
                'foreign_balance'=>$snapshot['foreign_balance'],
                'previous_base'=>$snapshot['previous_base'],
                'revalued_base'=>$snapshot['revalued_base'],
                'change_base'=>$snapshot['change_base'],
                'rate_to_base'=>$snapshot['rate_to_base'],
                'valuation_evidence'=>$data['valuation_evidence'],
                'data_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
        },3);
    }

    public function lines(FinanceFxRevaluation $source):array
    {
        abort_unless($source->posting_status==='not_posted',422,
            'This revaluation has already been posted.');
        $business=Business::query()->findOrFail($source->business_id);
        $snapshot=$this->basis($business,$source->bank_profile_id,
            $source->exchange_rate_id,$source->valued_on->toDateString());
        $snapshot['valuation_evidence']=$source->valuation_evidence;
        abort_unless($source->account_id===$snapshot['account_id']
            && $source->currency===$snapshot['currency']
            && BigDecimal::of((string)$source->foreign_balance)->isEqualTo($snapshot['foreign_balance'])
            && BigDecimal::of((string)$source->previous_base)->isEqualTo($snapshot['previous_base'])
            && BigDecimal::of((string)$source->revalued_base)->isEqualTo($snapshot['revalued_base'])
            && BigDecimal::of((string)$source->change_base)->isEqualTo($snapshot['change_base'])
            && BigDecimal::of((string)$source->rate_to_base)->isEqualTo($snapshot['rate_to_base'])
            && $source->data_snapshot==$snapshot
            && hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))===$source->sha256,
            422,'Bank ledger, exchange-rate evidence or journal valuation changed after preparation. Prepare a fresh independently reviewed valuation.');
        $delta=BigDecimal::of($snapshot['change_base']);
        $amount=(string)$delta->abs()->toScale(2);
        $ledger=app(FinanceLedger::class);
        if($delta->isGreaterThan(0)) return [
            $ledger->line($snapshot['account_key'],'0','0',$amount,'0',
                'Foreign bank book value increased; no foreign funds moved'),
            $ledger->line('unrealized_fx_gain','0','0','0',$amount,
                'Unrealised, non-cash currency revaluation gain'),
        ];
        return [
            $ledger->line($snapshot['account_key'],'0','0','0',$amount,
                'Foreign bank book value decreased; no foreign funds moved'),
            $ledger->line('unrealized_fx_loss','0','0',$amount,'0',
                'Unrealised, non-cash currency revaluation loss'),
        ];
    }
}
