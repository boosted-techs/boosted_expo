<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceOpeningBalance;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceOpeningBalances
{
    public function prepare(Business $business,array $data,int $actor):FinanceOpeningBalance
    {
        return DB::transaction(function()use($business,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $date=$data['effective_on'];
            app(FinancePeriodControls::class)->assertWritable($business,$date);
            if(FinanceOpeningBalance::query()->where('business_id',$business->id)->exists()) {
                throw ValidationException::withMessages(['opening'=>
                    'This business already has an opening balance submission. Only one is supported.']);
            }
            $oldest=FinanceJournal::query()->where('business_id',$business->id)
                ->orderBy('posted_on')->first();
            if($oldest && $date>$oldest->posted_on->toDateString()) {
                throw ValidationException::withMessages(['effective_on'=>
                    'Opening balances must be dated no later than the first recorded journal.']);
            }
            $ids=array_map(fn($l)=>(int)$l['account_id'],$data['lines']);
            if(count($ids)!==count(array_unique($ids))) {
                throw ValidationException::withMessages(['lines'=>'List each opening account only once.']);
            }
            $accounts=FinanceAccount::query()->where('business_id',$business->id)
                ->where('active',true)->whereIn('id',$ids)->get()->keyBy('id');
            if($accounts->count()!==count($ids)) abort(404,'Invalid opening account selection.');
            $debit=BigDecimal::zero();$credit=BigDecimal::zero();$lines=[];
            $math=app(FinanceMath::class);
            foreach($data['lines'] as $row) {
                $account=$accounts[(int)$row['account_id']];
                abort_unless(in_array($account->type,['asset','liability','equity'],true),422,
                    'Opening balances may use only assets, liabilities and equity.');
                if(\App\Models\FinanceBankProfile::query()->where('business_id',$business->id)
                    ->where('account_id',$account->id)
                    ->where('currency','!=',$business->base_currency)->exists()) {
                    throw ValidationException::withMessages(['lines'=>
                        'Foreign-currency bank openings need a foreign-balance and translated-base reconciliation workflow, which is not supported yet.']);
                }
                $dr=$math->money((string)($row['debit']??'0'));
                $cr=$math->money((string)($row['credit']??'0'));
                if($dr->isLessThan(0) || $cr->isLessThan(0)
                    || ($dr->isGreaterThan(0)===$cr->isGreaterThan(0))) {
                    throw ValidationException::withMessages(['lines'=>
                        'Every account must have a positive debit or a positive credit, not both.']);
                }
                $debit=$debit->plus($dr);$credit=$credit->plus($cr);
                $lines[]=[
                    'account_id'=>$account->id,'account'=>$account->system_key,
                    'account_label'=>$account->code.' · '.$account->name,
                    'debit'=>(string)$dr,'credit'=>(string)$cr,
                ];
            }
            if(count($lines)<2 || !$debit->isEqualTo($credit) || $debit->isZero()) {
                throw ValidationException::withMessages(['lines'=>
                    'Opening debits and credits must balance exactly, with at least two accounts.']);
            }
            usort($lines,fn($a,$b)=>$a['account_id']<=>$b['account_id']);
            $snapshot=['base_currency'=>$business->base_currency,'effective_on'=>$date,
                'lines'=>$lines,'debits'=>(string)$debit,'credits'=>(string)$credit];
            return FinanceOpeningBalance::create([
                'business_id'=>$business->id,'reference'=>'OPEN-'.Str::upper((string)Str::ulid()),
                'effective_on'=>$date,'currency'=>$business->base_currency,
                'data_snapshot'=>$snapshot,
                'sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'posting_status'=>'not_posted','created_by'=>$actor,
            ]);
        },3);
    }

    public function lines(FinanceOpeningBalance $source):array
    {
        $data=$source->data_snapshot;
        abort_unless(hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))
            ===$source->sha256,422,'Opening balances changed after preparation.');
        abort_unless($source->currency===$data['base_currency']
            && $source->effective_on->toDateString()===$data['effective_on'],422,
            'Opening document metadata does not match its snapshot.');
        $business=Business::query()->findOrFail($source->business_id);
        $oldest=FinanceJournal::query()->where('business_id',$business->id)
            ->orderBy('posted_on')->first();
        abort_unless(!$oldest || $source->effective_on->toDateString()
            <=$oldest->posted_on->toDateString(),422,
            'Existing journals predate the proposed opening balances.');
        $lines=[];
        foreach($data['lines'] as $row) {
            $account=FinanceAccount::query()->where('business_id',$business->id)
                ->where('active',true)->findOrFail($row['account_id']);
            abort_unless($account->system_key===$row['account']
                && in_array($account->type,['asset','liability','equity'],true),422,
                'An opening balance account was changed or disabled.');
            $lines[]=app(FinanceLedger::class)->line(
                $account->system_key,$row['debit'],$row['credit'],
                $row['debit'],$row['credit'],'Approved opening balance',
            );
        }
        return $lines;
    }
}
