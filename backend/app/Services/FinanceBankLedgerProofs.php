<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankLedgerCheckEvent;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatement;
use App\Models\FinanceJournalLine;
use App\Models\FinanceWorkflowLeg;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceBankLedgerProofs
{
    /**
     * Compare the bank CSV to actual posted ledger movements in the statement's
     * original currency. This is a bookkeeping consistency check, NOT proof that
     * an uploaded CSV was supplied/authenticated by the bank.
     */
    public function preview(FinanceBankStatement $statement): array
    {
        $business = Business::query()->findOrFail($statement->business_id);
        abort_unless($statement->status === 'reconciled', 422,
            'Finish the independent statement-line matching before checking ledger balances.');
        $profile = FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('account_id', $statement->account_id)->first();
        if (! $profile || $profile->currency !== $statement->currency) {
            throw ValidationException::withMessages(['statement' => 'Only named, single-currency company bank ledgers can receive full balance verification. Historical shared-bank data needs controlled reconstruction.']);
        }
        $from = $statement->period_from->toDateString();
        $to = $statement->period_to->toDateString();
        $opening = BigDecimal::zero();
        $closing = BigDecimal::zero();
        $baseOpening = BigDecimal::zero();
        $baseClosing = BigDecimal::zero();
        $movements = [];
        $digestLines = [];
        $openingLegs = FinanceWorkflowLeg::query()->where('business_id', $business->id)->whereHas('workflow', fn ($q) => $q->where('kind', 'cutover_opening'))->pluck('id')->all();
        $query = FinanceJournalLine::query()
            ->where('finance_journal_lines.business_id', $business->id)
            ->where('finance_journal_lines.account_id', $statement->account_id)
            ->join('finance_journals as j', function ($join) {
                $join->on('j.id', '=', 'finance_journal_lines.journal_id')
                    ->on('j.business_id', '=', 'finance_journal_lines.business_id');
            })->whereDate('j.posted_on', '<=', $to)
            ->orderBy('j.posted_on')->orderBy('j.id')->orderBy('finance_journal_lines.id');
        foreach ($query->get([
            'finance_journal_lines.id', 'finance_journal_lines.debit',
            'finance_journal_lines.credit', 'finance_journal_lines.base_debit',
            'finance_journal_lines.base_credit', 'j.id as journal_id', 'j.currency',
            'j.source_type', 'j.source_id', 'j.posted_on',
        ]) as $line) {
            if ($line->currency !== $statement->currency) {
                throw ValidationException::withMessages(['statement' => 'A journal is recorded against this bank in a different currency. Resolve the account assignment first.']);
            }
            $native = BigDecimal::of((string) $line->debit)->minus((string) $line->credit);
            $base = BigDecimal::of((string) $line->base_debit)->minus((string) $line->base_credit);
            $date = substr((string) $line->posted_on, 0, 10);
            $closing = $closing->plus($native);
            $baseClosing = $baseClosing->plus($base);
            $openingSource = in_array($line->source_type, ['opening_balance', 'foreign_bank_opening'], true) || ($line->source_type === 'workflow_leg' && in_array($line->source_id, $openingLegs, true));
            if ($date < $from || ($date === $from && $openingSource)) {
                $opening = $opening->plus($native);
                $baseOpening = $baseOpening->plus($base);
            } elseif (! $openingSource && ! $native->isZero()) {
                // FX revaluation changes only the base-currency book valuation.
                // It must NEVER appear as an invented bank transfer in the
                // original account currency or require a bank-statement row.
                $movements[] = [
                    'journal_id' => $line->journal_id, 'source_type' => $line->source_type,
                    'source_id' => $line->source_id, 'amount' => (string) $native->abs()->toScale(2),
                    'direction' => $native->isGreaterThan(0) ? 'credit' : 'debit',
                    'date' => $date,
                ];
            }
            $digestLines[] = [
                'line_id' => $line->id, 'journal_id' => $line->journal_id,
                'date' => $date, 'source_type' => $line->source_type, 'source_id' => $line->source_id,
                'debit' => (string) $line->debit, 'credit' => (string) $line->credit,
                'base_debit' => (string) $line->base_debit,
                'base_credit' => (string) $line->base_credit,
            ];
        }
        $matched = FinanceBankMatch::query()
            ->where('finance_bank_matches.business_id', $business->id)
            ->where('finance_bank_matches.status', 'confirmed')
            ->join('finance_bank_statement_lines as l', function ($join) {
                $join->on('l.id', '=', 'finance_bank_matches.statement_line_id')
                    ->on('l.business_id', '=', 'finance_bank_matches.business_id');
            })->where('l.statement_id', $statement->id)
            ->orderBy('l.line_number')
            ->get([
                'l.line_number', 'l.transaction_on', 'l.direction', 'l.amount',
                'finance_bank_matches.source_type', 'finance_bank_matches.source_id',
            ]);
        $lineCount = $statement->lines()->count();
        abort_unless($matched->count() === $lineCount, 422,
            'Every imported bank line must have an independently confirmed transaction match.');
        $expected = [];
        foreach ($matched as $match) {
            $expected[] = [
                'source_type' => $match->source_type === 'money_transfer_in' ? 'money_operation' : $match->source_type, 'source_id' => $match->source_id,
                'amount' => (string) BigDecimal::of((string) $match->amount)->toScale(2),
                'direction' => $match->direction,
            ];
        }
        $actual = array_map(fn ($m) => [
            'source_type' => $m['source_type'], 'source_id' => $m['source_id'],
            'amount' => $m['amount'], 'direction' => $m['direction'],
        ], $movements);
        $sort = fn (&$array) => usort($array, fn ($a, $b) => strcmp(
            json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        $sort($expected);
        $sort($actual);
        $allMovementsAccounted = $expected === $actual;
        $openingMatches = $opening->isEqualTo((string) $statement->opening_balance);
        $closingMatches = $closing->isEqualTo((string) $statement->closing_balance);

        return [
            'statement_id' => $statement->id, 'statement_reference' => $statement->reference,
            'statement_file_sha256' => $statement->file_sha256,
            'account_id' => $statement->account_id, 'bank_profile_id' => $profile->id,
            'business_id' => $business->id,
            'currency' => $statement->currency, 'base_currency' => $business->base_currency,
            'period_from' => $from, 'period_to' => $to,
            'statement_opening' => (string) $statement->opening_balance,
            'statement_closing' => (string) $statement->closing_balance,
            'book_opening' => (string) $opening->toScale(2),
            'book_closing' => (string) $closing->toScale(2),
            'book_base_opening' => (string) $baseOpening->toScale(2),
            'book_base_closing' => (string) $baseClosing->toScale(2),
            'opening_variance' => (string) $opening->minus((string) $statement->opening_balance)->toScale(2),
            'closing_variance' => (string) $closing->minus((string) $statement->closing_balance)->toScale(2),
            'opening_matches' => $openingMatches, 'closing_matches' => $closingMatches,
            'movement_matches' => $allMovementsAccounted,
            'ledger_movement_count' => count($movements),
            'statement_line_count' => $lineCount,
            'journal_fingerprint' => hash('sha256', json_encode($digestLines, JSON_THROW_ON_ERROR)),
            'bank_movement_fingerprint' => hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)),
            'statement_movement_fingerprint' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)),
        ];
    }

    private function assertBalancesMatch(array $snapshot): void
    {
        if (! $snapshot['opening_matches'] || ! $snapshot['closing_matches']
            || ! $snapshot['movement_matches']) {
            throw ValidationException::withMessages(['statement' => 'Book and bank balances or the posted bank movements differ. Verify opening source currency, missing ledger postings, payment dates and any unmatched bank-side transaction before approval. No journal has been changed.']);
        }
    }

    private function event(FinanceBankLedgerCheck $check, int $actor, string $action,
        string $notes, ?array $snapshot = null): void
    {
        FinanceBankLedgerCheckEvent::create([
            'business_id' => $check->business_id, 'check_id' => $check->id,
            'actor_id' => $actor, 'action' => $action, 'notes' => $notes,
            'snapshot' => $snapshot, 'created_at' => now(),
        ]);
    }

    public function submit(FinanceBankStatement $statement, int $actor,
        string $notes): FinanceBankLedgerCheck
    {
        return DB::transaction(function () use ($statement, $actor, $notes) {
            $business = Business::query()->whereKey($statement->business_id)
                ->lockForUpdate()->firstOrFail();
            $statement = FinanceBankStatement::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($statement->id);
            $snapshot = $this->preview($statement);
            $this->assertBalancesMatch($snapshot);
            $check = FinanceBankLedgerCheck::query()->where('business_id', $business->id)
                ->where('statement_id', $statement->id)->lockForUpdate()->first();
            abort_if($check?->superseded_by_check_id, 422, 'This historical proof was formally superseded. Use its reviewed replacement.');
            $staleVerified = $check && $check->status === 'verified' && ! $this->isCurrent($check);
            abort_unless(! $check || $check->status === 'rejected' || $staleVerified, 422,
                'This bank ledger verification is already awaiting review or remains current.');
            if ($staleVerified) {
                $this->event($check, $actor, 'superseded',
                    'Previously verified evidence is stale; a new independent check is required.',
                    $check->data_snapshot);
            }
            $sha = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($check) {
                $check->update([
                    'data_snapshot' => $snapshot, 'sha256' => $sha, 'status' => 'pending',
                    'prepared_by' => $actor, 'reviewed_by' => null, 'review_notes' => null, 'verified_at' => null,
                ]);
            } else {
                $check = FinanceBankLedgerCheck::create([
                    'business_id' => $business->id, 'statement_id' => $statement->id,
                    'data_snapshot' => $snapshot, 'sha256' => $sha,
                    'status' => 'pending', 'prepared_by' => $actor,
                ]);
            }
            $this->event($check, $actor, 'submitted', $notes, $snapshot);

            return $check;
        }, 3);
    }

    public function isCurrent(FinanceBankLedgerCheck $check): bool
    {
        if ($check->status !== 'verified' || $check->superseded_by_check_id) {
            return false;
        }
        try {
            $snapshot = $this->preview($check->statement);

            return $snapshot == $check->data_snapshot
                && hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)) === $check->sha256
                && $snapshot['opening_matches'] && $snapshot['closing_matches']
                && $snapshot['movement_matches'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function supersede(FinanceBankLedgerCheck $old, FinanceBankLedgerCheck $replacement, int $actor, string $note): void
    {
        DB::transaction(function () use ($old, $replacement, $actor, $note) {
            Business::query()->whereKey($old->business_id)->lockForUpdate()->firstOrFail();
            $old = FinanceBankLedgerCheck::query()->where('business_id', $old->business_id)->lockForUpdate()->findOrFail($old->id);
            $replacement = FinanceBankLedgerCheck::query()->where('business_id', $old->business_id)->lockForUpdate()->findOrFail($replacement->id);
            abort_unless($old->id !== $replacement->id && $old->status === 'verified' && ! $old->superseded_by_check_id && $old->prepared_by !== $actor && $replacement->prepared_by !== $actor, 422, 'A separate reviewer must supersede the original verified evidence once.');
            $a = $old->statement;
            $b = $replacement->statement;
            abort_unless(! $a->lines()->exists() && $a->account_id === $b->account_id && $a->currency === $b->currency && $b->period_from <= $a->period_from && $b->period_to >= $a->period_to && $this->isCurrent($replacement), 422, 'Only an empty balance statement can be superseded by current independently verified evidence covering the same account and period.');
            $snapshot = ['old_id' => $old->id, 'old_sha' => $old->sha256, 'replacement_id' => $replacement->id, 'replacement_sha' => $replacement->sha256, 'business_id' => $old->business_id, 'account_id' => $a->account_id, 'currency' => $a->currency, 'actor' => $actor, 'note' => $note];
            $old->update(['superseded_by_check_id' => $replacement->id, 'superseded_by' => $actor, 'superseded_at' => now(), 'supersession_note' => $note, 'supersession_snapshot' => $snapshot, 'supersession_hash' => app(FinanceWorkflows::class)->hash($snapshot)]);
            $this->event($old, $actor, 'balance_evidence_superseded', $note, $snapshot);
            app(PlatformAccess::class)->audit($old->business_id, $actor, 'finance.bank.balance_superseded', $note, $old->data_snapshot, $snapshot);
        }, 3);
    }

    public function isSuperseded(FinanceBankLedgerCheck $old): bool
    {
        if (! $old->superseded_by_check_id || ! $old->supersession_snapshot || ! hash_equals((string) $old->supersession_hash, app(FinanceWorkflows::class)->hash($old->supersession_snapshot))) {
            return false;
        }
        $s = $old->supersession_snapshot;
        if ($s['old_id'] !== $old->id || $s['old_sha'] !== $old->sha256 || $s['replacement_id'] !== $old->superseded_by_check_id || $s['actor'] !== $old->superseded_by || $s['note'] !== $old->supersession_note || ! $old->superseded_at || $old->statement->lines()->exists()) {
            return false;
        }
        $replacement = FinanceBankLedgerCheck::query()->where('business_id', $old->business_id)->find($old->superseded_by_check_id);

        return $replacement && $replacement->statement->account_id === $old->statement->account_id && $replacement->statement->currency === $old->statement->currency && $replacement->statement->period_from <= $old->statement->period_from && $replacement->statement->period_to >= $old->statement->period_to && $this->isCurrent($replacement);
    }

    public function review(FinanceBankLedgerCheck $check, bool $approve, int $actor,
        string $notes): FinanceBankLedgerCheck
    {
        return DB::transaction(function () use ($check, $approve, $actor, $notes) {
            $business = Business::query()->whereKey($check->business_id)
                ->lockForUpdate()->firstOrFail();
            $check = FinanceBankLedgerCheck::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($check->id);
            abort_unless($check->status === 'pending', 422, 'This bank ledger check is no longer pending.');
            abort_unless($check->prepared_by !== $actor, 422,
                'An independent owner or administrator must verify the bank-to-ledger proof.');
            if ($approve) {
                $preview = $this->preview($check->statement);
                $this->assertBalancesMatch($preview);
                abort_unless($preview == $check->data_snapshot
                    && hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR)) === $check->sha256, 422,
                    'Book figures or confirmed bank matches changed after the proposal. Prepare a fresh verification.');
            }
            $check->update([
                'status' => $approve ? 'verified' : 'rejected',
                'reviewed_by' => $actor, 'review_notes' => $notes,
                'verified_at' => $approve ? now() : null,
            ]);
            $this->event($check, $actor, $approve ? 'verified' : 'rejected', $notes,
                $approve ? $check->data_snapshot : null);

            return $check;
        }, 3);
    }
}
