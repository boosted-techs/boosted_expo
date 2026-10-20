<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceLedger
{
    /**
     * Only FinancePosting::post() may invoke this method during its locked
     * database transaction. Entries and lines have no edit/delete endpoints.
     */
    public function record(
        Business $business,string $type,int $sourceId,string $currency,string $rate,
        string $postedOn,string $description,int $postedBy,int $approvedBy,array $lines,
    ):FinanceJournal {
        if(count($lines)<2) throw ValidationException::withMessages([
            'journal'=>'Every journal requires at least two balanced lines.',
        ]);
        $math=app(FinanceMath::class);
        $debit=BigDecimal::zero();$credit=BigDecimal::zero();
        $baseDebit=BigDecimal::zero();$baseCredit=BigDecimal::zero();
        $accounts=[];
        foreach($lines as $item) {
            $key=$item['account'];
            $account=$accounts[$key]??app(FinanceSetup::class)->account($business->id,$key);
            $accounts[$key]=$account;
            foreach(['debit','credit','base_debit','base_credit'] as $field) {
                if(!isset($item[$field]) || $math->money((string)$item[$field])->isLessThan(0)) {
                    throw ValidationException::withMessages(['journal'=>'Journal amounts must be nonnegative and complete.']);
                }
            }
            $dr=$math->money((string)$item['debit']);
            $cr=$math->money((string)$item['credit']);
            $bdr=$math->money((string)$item['base_debit']);
            $bcr=$math->money((string)$item['base_credit']);
            if(($dr->isGreaterThan(0) && $cr->isGreaterThan(0))
                || ($bdr->isGreaterThan(0) && $bcr->isGreaterThan(0))) {
                throw ValidationException::withMessages(['journal'=>'A journal line cannot be both a debit and a credit.']);
            }
            if($dr->isZero() && $cr->isZero() && !in_array($key,['fx_gain','fx_loss'],true)) {
                throw ValidationException::withMessages(['journal'=>'Base-only journal lines are limited to currency gains and losses.']);
            }
            if(($dr->isZero() && $cr->isZero() && $bdr->isZero() && $bcr->isZero())) {
                throw ValidationException::withMessages(['journal'=>'Zero-amount journal lines are not allowed.']);
            }
            $debit=$debit->plus($dr);$credit=$credit->plus($cr);
            $baseDebit=$baseDebit->plus($bdr);$baseCredit=$baseCredit->plus($bcr);
        }
        if($debit->isLessThanOrEqualTo(0) || !$debit->isEqualTo($credit)
            || $baseDebit->isLessThanOrEqualTo(0) || !$baseDebit->isEqualTo($baseCredit)) {
            throw ValidationException::withMessages([
                'journal'=>'Financial journal must balance in both document and base currency.',
            ]);
        }
        if(FinanceJournal::query()->where('business_id',$business->id)
            ->where('source_type',$type)->where('source_id',$sourceId)->exists()) {
            abort(422,'This document has already been posted.');
        }
        $journal=FinanceJournal::create([
            'business_id'=>$business->id,'reference'=>'JRN-'.Str::upper((string)Str::ulid()),
            'source_type'=>$type,'source_id'=>$sourceId,
            'posted_on'=>$postedOn,'currency'=>$currency,'base_currency'=>$business->base_currency,
            'rate_to_base'=>$rate,'description'=>$description,
            'created_by'=>$postedBy,'approved_by'=>$approvedBy,
        ]);
        foreach($lines as $item) {
            $account=$accounts[$item['account']];
            FinanceJournalLine::create([
                'business_id'=>$business->id,'journal_id'=>$journal->id,
                'account_id'=>$account->id,'debit'=>(string)$math->money((string)$item['debit']),
                'credit'=>(string)$math->money((string)$item['credit']),
                'base_debit'=>(string)$math->money((string)$item['base_debit']),
                'base_credit'=>(string)$math->money((string)$item['base_credit']),
                'memo'=>$item['memo']??null,
            ]);
        }
        return $journal;
    }

    public function line(
        string $account,string $debit,string $credit,string $baseDebit,string $baseCredit,
        ?string $memo=null,
    ):array {
        return [
            'account'=>$account,'debit'=>$debit,'credit'=>$credit,
            'base_debit'=>$baseDebit,'base_credit'=>$baseCredit,'memo'=>$memo,
        ];
    }
}
