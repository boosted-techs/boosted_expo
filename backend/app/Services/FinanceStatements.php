<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class FinanceStatements
{
    private function amounts(int $businessId,?string $from,string $to):array
    {
        $query=FinanceJournalLine::query()->where('finance_journal_lines.business_id',$businessId)
            ->join('finance_journals as j',function($join){
                $join->on('j.id','=','finance_journal_lines.journal_id')
                    ->on('j.business_id','=','finance_journal_lines.business_id');
            })->whereDate('j.posted_on','<=',$to);
        if($from!==null) $query->whereDate('j.posted_on','>=',$from);
        $amounts=[];
        foreach($query->get(['finance_journal_lines.account_id','finance_journal_lines.base_debit',
            'finance_journal_lines.base_credit']) as $line) {
            $id=$line->account_id;
            $amounts[$id]=($amounts[$id]??BigDecimal::zero())
                ->plus((string)$line->base_debit)->minus((string)$line->base_credit);
        }
        return $amounts;
    }

    public function report(Business $business,string $from,string $to):array
    {
        $accounts=FinanceAccount::query()->where('business_id',$business->id)
            ->orderBy('code')->get();
        $period=$this->amounts($business->id,$from,$to);
        $closing=$this->amounts($business->id,null,$to);
        $profitLines=[];$income=BigDecimal::zero();$expense=BigDecimal::zero();
        $byType=['asset'=>[],'liability'=>[],'equity'=>[]];
        $assets=BigDecimal::zero();$liabilities=BigDecimal::zero();$equity=BigDecimal::zero();
        $trialDebit=BigDecimal::zero();$trialCredit=BigDecimal::zero();
        foreach($accounts as $account) {
            $id=$account->id;
            $periodNet=$period[$id]??BigDecimal::zero();
            $closeNet=$closing[$id]??BigDecimal::zero();
            if(in_array($account->type,['income','expense'],true)) {
                $value=$account->type==='income'?$periodNet->negated():$periodNet;
                $profitLines[]=[
                    'account'=>$account,'value'=>(string)$value->toScale(2),
                ];
                if($account->type==='income') $income=$income->plus($value);
                else $expense=$expense->plus($value);
            }
            if($account->type==='asset') {
                $assets=$assets->plus($closeNet);
                $byType['asset'][]=[
                    'account'=>$account,'value'=>(string)$closeNet->toScale(2),
                ];
            } elseif($account->type==='liability') {
                $liabilities=$liabilities->plus($closeNet->negated());
                $byType['liability'][]=[
                    'account'=>$account,'value'=>(string)$closeNet->negated()->toScale(2),
                ];
            } elseif($account->type==='equity') {
                $equity=$equity->plus($closeNet->negated());
                $byType['equity'][]=['account'=>$account,
                    'value'=>(string)$closeNet->negated()->toScale(2)];
            }
            if($closeNet->isGreaterThan(0)) $trialDebit=$trialDebit->plus($closeNet);
            elseif($closeNet->isLessThan(0)) $trialCredit=$trialCredit->plus($closeNet->negated());
        }
        $cumulativeIncome=BigDecimal::zero();$cumulativeExpenses=BigDecimal::zero();
        foreach($accounts as $account) {
            $balance=$closing[$account->id]??BigDecimal::zero();
            if($account->type==='income') $cumulativeIncome=$cumulativeIncome->minus($balance);
            if($account->type==='expense') $cumulativeExpenses=$cumulativeExpenses->plus($balance);
        }
        $cumulativeProfit=$cumulativeIncome->minus($cumulativeExpenses);
        return [
            'base_currency'=>$business->base_currency,'from'=>$from,'to'=>$to,
            'profit_lines'=>$profitLines,
            'income'=>(string)$income->toScale(2),
            'expense'=>(string)$expense->toScale(2),
            'net'=>(string)$income->minus($expense)->toScale(2),
            'balance_categories'=>$byType,
            'assets'=>(string)$assets->toScale(2),
            'liabilities'=>(string)$liabilities->toScale(2),
            'equity'=>(string)$equity->toScale(2),
            'cumulative_profit'=>(string)$cumulativeProfit->toScale(2),
            'balance_variance'=>(string)$assets->minus($liabilities)
                ->minus($equity)->minus($cumulativeProfit)->toScale(2),
            'trial_debit'=>(string)$trialDebit->toScale(2),
            'trial_credit'=>(string)$trialCredit->toScale(2),
            'has_opening_balances'=>\App\Models\FinanceOpeningBalance::query()
                ->where('business_id',$business->id)->where('posting_status','posted')->exists(),
            'journal_count'=>FinanceJournal::query()->where('business_id',$business->id)
                ->whereDate('posted_on','>=',$from)->whereDate('posted_on','<=',$to)->count(),
        ];
    }

    public function ledger(Business $business,FinanceAccount $account,
        string $from,string $to):array
    {
        $query=FinanceJournalLine::query()->where('finance_journal_lines.business_id',$business->id)
            ->where('account_id',$account->id)
            ->join('finance_journals as j',function($join){
                $join->on('j.id','=','finance_journal_lines.journal_id')
                    ->on('j.business_id','=','finance_journal_lines.business_id');
            });
        $opening=BigDecimal::zero();
        foreach((clone $query)->whereDate('j.posted_on','<',$from)
            ->get(['finance_journal_lines.base_debit','finance_journal_lines.base_credit']) as $line) {
            $opening=$opening->plus((string)$line->base_debit)
                ->minus((string)$line->base_credit);
        }
        $running=$opening;
        $entries=[];
        foreach((clone $query)->whereDate('j.posted_on','>=',$from)
            ->whereDate('j.posted_on','<=',$to)
            ->orderBy('j.posted_on')->orderBy('j.id')->orderBy('finance_journal_lines.id')
            ->get(['finance_journal_lines.id','finance_journal_lines.base_debit',
                'finance_journal_lines.base_credit','j.reference','j.id as journal_id','j.posted_on',
                'j.description']) as $line) {
            $running=$running->plus((string)$line->base_debit)
                ->minus((string)$line->base_credit);
            $entries[]=[
                'date'=>$line->posted_on,'reference'=>$line->reference,
                'journal_id'=>$line->journal_id,'description'=>$line->description,
                'debit'=>$line->base_debit,'credit'=>$line->base_credit,
                'balance'=>(string)$running->toScale(2),
            ];
        }
        return ['opening'=>(string)$opening->toScale(2),'closing'=>(string)$running->toScale(2),
            'entries'=>$entries];
    }
}
