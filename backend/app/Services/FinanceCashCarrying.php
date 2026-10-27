<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceBankProfile;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceJournal;
use App\Models\FinanceMoneyCorrection;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceWorkflowLeg;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceCashCarrying
{
    private function share(BigDecimal $value, BigDecimal $part, BigDecimal $total): BigDecimal
    {
        return $part->isEqualTo($total) ? $value : app(FinanceMath::class)->money($value->multipliedBy($part)->dividedBy($total, 14, RoundingMode::HalfUp));
    }

    private function pool(int $businessId, int $accountId): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach (FinanceFxRevaluation::query()->where('business_id', $businessId)->where('account_id', $accountId)->where('posting_status', 'posted')->pluck('change_base') as $v) {
            $total = $total->plus((string) $v);
        }foreach (DB::table('finance_cash_settlements')->where('business_id', $businessId)->where('account_id', $accountId)->get() as $row) {
            $proof = json_decode($row->data_snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($row->snapshot_hash, app(FinanceWorkflows::class)->hash($proof)) && BigDecimal::of((string) $row->unrealized_released)->isEqualTo($proof['unrealized_released']) && (int) $row->account_id === (int) $proof['account_id'], 422, 'The recorded money-account FX attribution changed.');
            $total = $total->minus((string) $row->unrealized_released);
        }

        return $total;
    }

    private function restoration(Business $b, array $source, int $account): array
    {
        $type = null;
        $id = null;
        $amount = null;
        if (in_array($source['type'], ['transaction_reversal', 'partial_bank_return'], true)) {
            $row = ($source['type'] === 'transaction_reversal' ? FinanceTransactionReversal::query() : FinancePartialBankReturn::query())->where('business_id', $b->id)->findOrFail($source['id']);
            $type = $row->source_type;
            $id = $row->source_id;
            $amount = (string) $row->amount;
        } elseif ($source['type'] === 'register_entry') {
            $row = FinanceRegisterEntry::query()->where('business_id', $b->id)->findOrFail($source['id']);
            if (in_array($row->kind, ['register_reversal', 'register_partial_return'], true)) {
                $type = 'register_entry';
                $id = $row->original_entry_id;
                $amount = (string) $row->cash_amount;
            }
        } elseif ($source['type'] === 'workflow_leg') {
            $leg = FinanceWorkflowLeg::query()->where('business_id', $b->id)->with('workflow')->findOrFail($source['id']);
            if ($leg->workflow->kind === 'workflow_correction') {
                $sourceLeg = $leg->workflow->data_snapshot['details']['sources'][$leg->sequence];
                $type = 'workflow_leg';
                $id = $sourceLeg['original_leg_id'];
                $amount = '0';
                foreach ($source['lines'] as $line) {
                    $key = FinanceAccount::query()->where('business_id', $b->id)->whereKey($account)->value('system_key');
                    if ($line['account'] === $key) {
                        $amount = $line['debit'];
                    }
                }
            } elseif ($leg->workflow->kind === 'money_correction') {
                $correction = FinanceMoneyCorrection::query()->where('business_id', $b->id)->where('workflow_id', $leg->workflow_id)->firstOrFail();
                $type = 'money_operation';
                $id = $correction->operation_id;
                $amount = (string) $correction->amount;
            }
        }
        if (! $type) {
            return ['amount' => BigDecimal::zero(), 'type' => null, 'id' => null, 'units' => '0.00'];
        }
        $prior = DB::table('finance_cash_settlements')->where('business_id', $b->id)->where('source_type', $type)->where('source_id', $id)->where('account_id', $account)->first();
        if (! $prior) {
            return ['amount' => BigDecimal::zero(), 'type' => null, 'id' => null, 'units' => '0.00'];
        }$proof = json_decode($prior->data_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $original = BigDecimal::of($proof['credit']);
        if (! $original->isGreaterThan(0)) {
            return ['amount' => BigDecimal::zero(), 'type' => null, 'id' => null, 'units' => '0.00'];
        }$used = BigDecimal::zero();
        foreach (DB::table('finance_cash_settlements')->where('business_id', $b->id)->where('account_id', $account)->where('restores_source_type', $type)->where('restores_source_id', $id)->pluck('restores_units') as $v) {
            $used = $used->plus((string) $v);
        }$part = BigDecimal::of($amount);
        abort_unless(! $used->plus($part)->isGreaterThan($original), 422, 'The bank FX restoration exceeds its original cash movement.');
        $value = BigDecimal::of((string) $prior->unrealized_released);
        $restored = $this->share($value, $used->plus($part), $original)->minus($used->isZero() ? BigDecimal::zero() : $this->share($value, $used, $original));

        return ['amount' => $restored, 'type' => $type, 'id' => $id, 'units' => (string) $part];
    }

    /** @param array<int,array{type:string,id:int,currency:string,date:string,lines:array}> $sources */
    public function snapshot(Business $b, array $sources): ?array
    {
        app(OrderOperations::class)->lockForWork($b->id, 'finance');
        $money = [];
        $grouped = [];
        $descriptors = [];
        $results = [];
        $math = app(FinanceMath::class);
        $flows = app(FinanceWorkflows::class);
        foreach ($sources as $source) {
            $key = $source['type'].':'.$source['id'];
            $descriptors[$key] = $source;
            $results[$key] = ['source' => $source['type'], 'id' => $source['id'], 'currency' => $source['currency'], 'date' => $source['date'], 'raw_hash' => $flows->hash($source['lines']), 'lines' => $source['lines'], 'accounts' => []];
            if ($source['currency'] === $b->base_currency) {
                continue;
            }
            foreach ($source['lines'] as $index => $line) {
                $account = FinanceAccount::query()->where('business_id', $b->id)->where('system_key', $line['account'])->firstOrFail();
                $profile = FinanceBankProfile::query()->where('business_id', $b->id)->where('account_id', $account->id)->first();
                $cash = $profile ? null : CashCustody::query()->where('business_id', $b->id)->where('account_id', $account->id)->first();
                if (! $profile && ! $cash) {
                    continue;
                }abort_unless(($profile?->currency ?? $cash->currency) === $source['currency'], 422, 'The money-account currency changed.');
                $debit = BigDecimal::of($line['debit']);
                $credit = BigDecimal::of($line['credit']);
                if ($debit->isZero() && $credit->isZero()) {
                    continue;
                }$basis = $flows->accountBasis($b->id, $account->id, $source['date']);
                abort_unless(! $basis['units']->isZero() || $basis['base']->isZero(), 422, 'The named money account has zero currency units with a residual book value. Reconcile its historical carrying value before new movement.');
                $token = $key.':'.$index;
                $money[$token] = ['key' => $key, 'index' => $index, 'account' => $account, 'basis' => $basis, 'pool' => $this->pool($b->id, $account->id), 'debit' => $debit, 'credit' => $credit, 'raw' => $line, 'source' => $source];
                $grouped[$source['currency']][] = $token;
            }
        }
        $internal = [];
        foreach ($grouped as $currency => $tokens) {
            if (count($tokens) !== 2) {
                continue;
            }[$a,$c] = array_map(fn ($t) => $money[$t], $tokens);
            $out = $a['credit']->isGreaterThan(0) ? $a : $c;
            $in = $a['debit']->isGreaterThan(0) ? $a : $c;
            $outToken = $a['credit']->isGreaterThan(0) ? $tokens[0] : $tokens[1];
            $inToken = $a['debit']->isGreaterThan(0) ? $tokens[0] : $tokens[1];
            if ($outToken === $inToken || $out['account']->id === $in['account']->id || ! $out['credit']->isEqualTo($in['debit']) || $out['basis']['units']->isLessThan($out['credit']) || $in['basis']['units']->isLessThan(0)) {
                continue;
            }$onlyMoney = true;
            foreach (array_unique([$out['key'], $in['key']]) as $sourceKey) {
                foreach ($descriptors[$sourceKey]['lines'] as $line) {
                    if (! in_array($line['account'], [$out['account']->system_key, $in['account']->system_key, 'fx_clearing', 'fx_gain', 'fx_loss', 'unrealized_fx_gain', 'unrealized_fx_loss'], true)) {
                        $onlyMoney = false;
                    }
                }
            }if ($onlyMoney) {
                $carry = $this->share($out['basis']['base'], $out['credit'], $out['basis']['units']);
                $pool = $this->share($out['pool'], $out['credit'], $out['basis']['units']);
                $internal[$outToken] = ['base' => $carry, 'released' => $pool];
                $internal[$inToken] = ['base' => $carry, 'released' => $pool->negated()];
            }
        }
        foreach ($money as $token => $m) {
            $key = $m['key'];
            $debit = $m['debit'];
            $credit = $m['credit'];
            $basis = $m['basis'];
            $line = $m['raw'];
            $released = BigDecimal::zero();
            $reclass = BigDecimal::zero();
            $restore = ['amount' => BigDecimal::zero(), 'type' => null, 'id' => null, 'units' => '0.00'];
            $changed = false;
            if (isset($internal[$token])) {
                $base = $internal[$token]['base'];
                $released = $internal[$token]['released'];
                if ($credit->isGreaterThan(0)) {
                    $line['base_credit'] = (string) $base;
                } else {
                    $line['base_debit'] = (string) $base;
                }$changed = true;
            } elseif ($credit->isGreaterThan(0)) {
                $base = BigDecimal::of($line['base_credit']);
                if ($basis['units']->isGreaterThan(0)) {
                    $used = BigDecimal::min($credit, $basis['units']);
                    $carry = $this->share($basis['base'], $used, $basis['units']);
                    $extra = $credit->minus($used);
                    $base = $carry->plus($extra->isGreaterThan(0) ? $math->money(BigDecimal::of($line['base_credit'])->multipliedBy($extra)->dividedBy($credit, 14, RoundingMode::HalfUp)) : BigDecimal::zero());
                    $released = $this->share($m['pool'], $used, $basis['units']);
                    $reclass = $released;
                }$line['base_credit'] = (string) $base;
                $changed = true;
            } else {
                $restore = $this->restoration($b, $m['source'], $m['account']->id);
                $restored = $restore['amount'];
                if ($basis['units']->isLessThan(0)) {
                    $covered = BigDecimal::min($debit, $basis['units']->abs());
                    $carry = $this->share($basis['base']->abs(), $covered, $basis['units']->abs());
                    $extra = $debit->minus($covered);
                    $line['base_debit'] = (string) $carry->plus($extra->isGreaterThan(0) ? $math->money(BigDecimal::of($line['base_debit'])->multipliedBy($extra)->dividedBy($debit, 14, RoundingMode::HalfUp)) : BigDecimal::zero());
                    $usedRestoration = $this->share($restored, $covered, $debit);
                    $restored = $restored->minus($usedRestoration);
                    $reclass = $usedRestoration;
                    $changed = true;
                }$released = $restored->negated();
                $changed = $changed || ! $restore['amount']->isZero();
            }
            if (! $changed) {
                continue;
            }$results[$key]['lines'][$m['index']] = $line;
            $results[$key]['accounts'][] = ['account_id' => $m['account']->id, 'label' => $m['account']->name, 'key' => $m['account']->system_key, 'basis_fingerprint' => $basis['fingerprint'], 'basis_units' => (string) $basis['units'], 'basis_base' => (string) $basis['base'], 'pool_before' => (string) $m['pool'], 'debit' => (string) $debit, 'credit' => (string) $credit, 'unrealized_released' => (string) $released, 'restores_source_type' => $restore['type'], 'restores_source_id' => $restore['id'], 'restores_units' => $restore['units'], 'internal_transfer' => isset($internal[$token])];
            if (! $reclass->isZero()) {
                $v = (string) $reclass->abs();
                $l = app(FinanceLedger::class);
                if ($reclass->isGreaterThan(0)) {
                    $results[$key]['lines'][] = $l->line('unrealized_fx_gain', '0', '0', $v, '0', 'Release settled foreign money-account gain');
                    $results[$key]['lines'][] = $l->line('fx_gain', '0', '0', '0', $v, 'Realised foreign money-account gain');
                } else {
                    $results[$key]['lines'][] = $l->line('unrealized_fx_loss', '0', '0', '0', $v, 'Release settled foreign money-account loss');
                    $results[$key]['lines'][] = $l->line('fx_loss', '0', '0', $v, '0', 'Realised foreign money-account loss');
                }
            }
        }
        $results = array_filter($results, fn ($result) => $result['accounts'] !== []);
        if (! $results) {
            return null;
        }foreach ($results as &$result) {
            if (count($result['accounts']) === 1 && $result['accounts'][0]['internal_transfer']) {
                foreach ($result['lines'] as &$line) {
                    if ($line['account'] === 'fx_clearing') {
                        $cashLine = collect($result['lines'])->firstWhere('account', $result['accounts'][0]['key']);
                        if (BigDecimal::of($line['debit'])->isGreaterThan(0)) {
                            $line['base_debit'] = $cashLine['base_credit'];
                        } else {
                            $line['base_credit'] = $cashLine['base_debit'];
                        }
                    }
                }unset($line);
            }$net = BigDecimal::zero();
            foreach ($result['lines'] as $line) {
                $net = $net->plus($line['base_debit'])->minus($line['base_credit']);
            }if (! $net->isZero()) {
                $v = (string) $net->abs();
                $result['lines'][] = app(FinanceLedger::class)->line($net->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $net->isLessThan(0) ? $v : '0', $net->isGreaterThan(0) ? $v : '0', 'Weighted cash carrying value compared with the documented settlement basis');
            }$result['hash'] = $flows->hash($result);
        }unset($result);

        return ['sources' => $results, 'method' => 'weighted_posted_currency_carrying_with_unrealised_release'];
    }

    public function lines(Business $b, string $type, int $id, string $date, array $raw, array $proof): array
    {
        $copy = $proof;
        unset($copy['hash']);
        abort_unless(hash_equals($proof['hash'], app(FinanceWorkflows::class)->hash($copy)) && $proof['source'] === $type && $proof['id'] === $id && $proof['date'] === $date && hash_equals($proof['raw_hash'], app(FinanceWorkflows::class)->hash($raw)), 422, 'The reviewed foreign cash valuation or its source lines changed.');
        foreach ($proof['accounts'] as $account) {
            $basis = app(FinanceWorkflows::class)->accountBasis($b->id, $account['account_id'], $date);
            abort_unless(hash_equals($basis['fingerprint'], $account['basis_fingerprint']) && $this->pool($b->id, $account['account_id'])->isEqualTo($account['pool_before']), 422, 'Foreign money balances changed after review. Withdraw and prepare a current financial review.');
        }

        return $proof['lines'];
    }

    public function record(FinanceJournal $journal, array $proof): void
    {
        foreach ($proof['accounts'] as $account) {
            DB::table('finance_cash_settlements')->insert(['business_id' => $journal->business_id, 'account_id' => $account['account_id'], 'journal_id' => $journal->id, 'source_type' => $journal->source_type, 'source_id' => $journal->source_id, 'unrealized_released' => $account['unrealized_released'], 'restores_source_type' => $account['restores_source_type'], 'restores_source_id' => $account['restores_source_id'], 'restores_units' => $account['restores_units'], 'data_snapshot' => json_encode($account, JSON_THROW_ON_ERROR), 'snapshot_hash' => app(FinanceWorkflows::class)->hash($account), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
