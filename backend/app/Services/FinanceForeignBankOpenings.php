<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankProfile;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceJournalLine;
use App\Models\FinanceExchangeRate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceForeignBankOpenings
{
    private function validateBank(Business $business,int $profileId,
        string $currency):FinanceBankProfile
    {
        $profile=FinanceBankProfile::query()->where('business_id',$business->id)
            ->where('active',true)->with('account')->findOrFail($profileId);
        abort_unless($profile->currency===$currency
            && $profile->currency!==$business->base_currency
            && $profile->account?->active
            && $profile->account->business_id===$business->id
            && $profile->account->type==='asset',422,
            'Choose a company-owned, active foreign-currency bank account.');
        return $profile;
    }

    private function hasBankEntries(Business $business,int $accountId):bool
    {
        return FinanceJournalLine::query()->where('business_id',$business->id)
            ->where('account_id',$accountId)->exists();
    }

    public function prepare(Business $business,array $data,int $actor):FinanceForeignBankOpening
    {
        return DB::transaction(function()use($business,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $profile=$this->validateBank($business,(int)$data['bank_profile_id'],
                strtoupper($data['currency']));
            if(FinanceForeignBankOpening::query()->where('business_id',$business->id)
                ->where('account_id',$profile->account_id)->exists()) {
                throw ValidationException::withMessages(['bank_profile_id'=>
                    'This foreign bank already has an opening balance record. Further adjustments need a separate approval process.']);
            }
            if($this->hasBankEntries($business,$profile->account_id)) {
                throw ValidationException::withMessages(['bank_profile_id'=>
                    'This bank already has ledger activity. Opening balances must be entered before the first movement.']);
            }
            $date=$data['effective_on'];
            app(FinancePeriodControls::class)->assertWritable($business,$date);
            $rateId=(int)$data['exchange_rate_id'];
            $rate=app(FinanceRates::class)->rate($business,$profile->currency,$rateId,$date);
            $fx=FinanceExchangeRate::query()->where('business_id',$business->id)
                ->where('status','approved')->findOrFail($rateId);
            $amount=app(FinanceMath::class)->money((string)$data['foreign_amount']);
            if($amount->isLessThanOrEqualTo(0)) throw ValidationException::withMessages([
                'foreign_amount'=>'Only positive opening bank assets are supported; overdrafts need a separate liability workflow.',
            ]);
            $base=app(FinanceMath::class)->base($amount,$rate);
            if($base->isLessThanOrEqualTo(0)) throw ValidationException::withMessages([
                'foreign_amount'=>'The translated base amount must be positive.',
            ]);
            $snapshot=[
                'bank_profile_id'=>$profile->id,'account_id'=>$profile->account_id,
                'account_key'=>$profile->account->system_key,'currency'=>$profile->currency,
                'base_currency'=>$business->base_currency,
                'foreign_amount'=>(string)$amount->toScale(2),
                'base_amount'=>(string)$base->toScale(2),
                'exchange_rate_id'=>$rateId,'rate_to_base'=>(string)$rate,
                'rate_source_note'=>$fx->source_note,
                'effective_on'=>$date,'bank_evidence'=>$data['bank_evidence'],
            ];
            return FinanceForeignBankOpening::create([
                'business_id'=>$business->id,'bank_profile_id'=>$profile->id,
                'account_id'=>$profile->account_id,'exchange_rate_id'=>$rateId,
                'reference'=>'FXOPEN-'.Str::upper((string)Str::ulid()),
                'currency'=>$profile->currency,
                'foreign_amount'=>$snapshot['foreign_amount'],
                'base_amount'=>$snapshot['base_amount'],'rate_to_base'=>$rate,
                'effective_on'=>$date,'bank_evidence'=>$data['bank_evidence'],
                'data_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
        },3);
    }

    public function lines(FinanceForeignBankOpening $opening):array
    {
        abort_unless($opening->posting_status==='not_posted',422,
            'Foreign opening has already been posted.');
        $business=Business::query()->findOrFail($opening->business_id);
        $data=$opening->data_snapshot;
        abort_unless(is_array($data)
            && hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))===$opening->sha256
            && $data['bank_profile_id']===$opening->bank_profile_id
            && $data['account_id']===$opening->account_id
            && $data['exchange_rate_id']===$opening->exchange_rate_id
            && $data['effective_on']===$opening->effective_on->toDateString()
            && $data['bank_evidence']===$opening->bank_evidence
            && $data['currency']===$opening->currency
            && $data['base_currency']===$business->base_currency
            && BigDecimal::of($data['foreign_amount'])->isEqualTo((string)$opening->foreign_amount)
            && BigDecimal::of($data['base_amount'])->isEqualTo((string)$opening->base_amount)
            && BigDecimal::of($data['rate_to_base'])->isEqualTo((string)$opening->rate_to_base),
            422,'Foreign bank opening was changed after preparation.');
        $profile=$this->validateBank($business,$opening->bank_profile_id,$opening->currency);
        abort_unless($profile->account_id===$opening->account_id
            && $profile->account->system_key===$data['account_key'],422,
            'Bank ledger configuration changed after opening preparation.');
        $rate=app(FinanceRates::class)->rate($business,$opening->currency,
            $opening->exchange_rate_id,$opening->effective_on->toDateString());
        abort_unless(BigDecimal::of($rate)->isEqualTo((string)$opening->rate_to_base)
            && app(FinanceMath::class)->base(
                BigDecimal::of((string)$opening->foreign_amount),$rate
            )->isEqualTo((string)$opening->base_amount),422,
            'The approved historical rate does not match the prepared opening.');
        if($this->hasBankEntries($business,$opening->account_id)) {
            throw ValidationException::withMessages(['bank_profile_id'=>
                'Another journal affected this bank before its opening balance could be posted.']);
        }
        $ledger=app(FinanceLedger::class);
        return [
            $ledger->line($data['account_key'],$data['foreign_amount'],'0',
                $data['base_amount'],'0','Historical bank opening, original foreign currency'),
            $ledger->line('opening_equity','0',$data['foreign_amount'],
                '0',$data['base_amount'],
                'Historical capital translated using approved bank opening rate'),
        ];
    }
}
