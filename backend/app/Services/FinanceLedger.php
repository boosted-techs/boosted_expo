<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceOpeningItem;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceWorkflowLeg;
use App\Models\LoanFacility;
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
        Business $business, string $type, int $sourceId, string $currency, string $rate,
        string $postedOn, string $description, int $postedBy, int $approvedBy, array $lines, ?array $cashProof = null,
    ): FinanceJournal {
        Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
        app(FinancePeriodControls::class)->assertWritable($business, $postedOn);
        if ($type === 'workflow_leg') {
            $leg = FinanceWorkflowLeg::query()->where('business_id', $business->id)->with('workflow')->findOrFail($sourceId);
            abort_unless($leg->workflow?->business_id === $business->id && $leg->posting_status === 'not_posted' && $leg->workflow->posting_status === 'not_posted' && $leg->lines === $lines && $leg->currency === $currency && $leg->event_on->toDateString() === $postedOn && BigDecimal::of((string) $leg->rate_to_base)->isEqualTo($rate), 422, 'Use the exact reviewed workflow accounting leg.');
        }
        if ($type === 'monetary_revaluation') {
            $value = FinanceMonetaryRevaluation::query()
                ->where('business_id', $business->id)->findOrFail($sourceId);
            $key = $value->source_type === 'commercial_invoice' ? 'receivable' : 'payable';
            abort_unless(in_array($value->source_type, ['commercial_invoice', 'supplier_bill'], true)
                && count($lines) === 2
                && collect($lines)->pluck('account')->unique()->count() === 2
                && collect($lines)->contains(fn ($row) => ($row['account'] ?? '') === $key)
                && collect($lines)->every(fn ($row) => in_array($row['account'] ?? '',
                    [$key, 'unrealized_fx_gain', 'unrealized_fx_loss'], true)), 422,
                'Document revaluation can modify only its receivable/payable and unrealised FX result.');
        }
        if ($type === 'fx_revaluation') {
            $value = FinanceFxRevaluation::query()
                ->where('business_id', $business->id)->findOrFail($sourceId);
            $bank = FinanceAccount::query()->where('business_id', $business->id)
                ->findOrFail($value->account_id);
            $allowed = [$bank->system_key, 'unrealized_fx_gain', 'unrealized_fx_loss'];
            abort_unless(count($lines) === 2
                && collect($lines)->pluck('account')->unique()->count() === 2
                && collect($lines)->every(fn ($row) => in_array($row['account'] ?? '', $allowed, true))
                && collect($lines)->contains(fn ($row) => ($row['account'] ?? '') === $bank->system_key), 422,
                'Revaluation journals may affect one named foreign bank and the unrealised gain/loss only.');
        }
        $registerRevaluation = false;
        if ($type === 'register_entry') {
            $entry = FinanceRegisterEntry::query()->where('business_id', $business->id)->findOrFail($sourceId);
            abort_unless($entry->data_snapshot['lines'] === $lines, 422, 'Register journals must use their reviewed immutable lines.');
            $registerRevaluation = $entry->kind === 'loan_revaluation' || ($entry->kind === 'register_reversal' && ($entry->data_snapshot['details']['kind'] ?? null) === 'loan_revaluation');
            if ($registerRevaluation) {
                $loan = LoanFacility::query()->where('business_id', $business->id)->findOrFail($entry->loan_id);
                $allowed = [app(LoanRegister::class)->account($loan), $loan->loan_direction === 'lend' ? 'loan_interest_receivable' : 'loan_interest_payable', $loan->loan_direction === 'lend' ? 'loan_fees_receivable' : 'loan_fees_payable', 'unrealized_fx_gain', 'unrealized_fx_loss'];
                abort_unless(collect($lines)->every(fn ($row) => in_array($row['account'], $allowed, true)), 422, 'Loan FX journals may adjust only the loan carrying value and unrealised FX.');
            }
        }
        if ($cashProof) {
            $lines = app(FinanceCashCarrying::class)->lines($business, $type, $sourceId, $postedOn, $lines, $cashProof);
        }
        $openingRevaluation = $type === 'workflow_leg' && $leg->workflow->kind === 'opening_item_revaluation';
        if ($openingRevaluation) {
            $item = FinanceOpeningItem::query()->where('business_id', $business->id)->findOrFail($leg->workflow->data_snapshot['details']['opening_item_id']);
            $control = FinanceCutovers::CONTROLS[$item->kind][0];
            abort_unless(count($lines) === 2 && collect($lines)->every(fn ($line) => in_array($line['account'], [$control, 'unrealized_fx_gain', 'unrealized_fx_loss'], true)), 422, 'Opening FX can change only the reviewed monetary control and unrealised result.');
        }
        $noncashFx = $openingRevaluation || in_array($type, ['fx_revaluation', 'monetary_revaluation'], true) || $registerRevaluation;
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'journal' => 'Every journal requires at least two balanced lines.',
            ]);
        }
        $math = app(FinanceMath::class);
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $baseDebit = BigDecimal::zero();
        $baseCredit = BigDecimal::zero();
        $accounts = [];
        foreach ($lines as $item) {
            $key = $item['account'];
            $account = $accounts[$key] ?? app(FinanceSetup::class)->account($business->id, $key);
            $accounts[$key] = $account;
            if ($account->type === 'asset' && (str_starts_with($key, 'cash_') || CashCustody::query()->where('business_id', $business->id)->where('account_id', $account->id)->exists())) {
                abort_if(CashClose::query()->where('business_id', $business->id)->where('status', 'closed')->whereDate('counted_on', '>=', $postedOn)->whereIn('custody_id', CashCustody::query()->where('business_id', $business->id)->where('account_id', $account->id)->select('id'))->exists(), 422, 'This cash day is closed. An owner must reopen the cash count before posting earlier money changes.');
            }
            foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $field) {
                if (! isset($item[$field]) || $math->money((string) $item[$field])->isLessThan(0)) {
                    throw ValidationException::withMessages(['journal' => 'Journal amounts must be nonnegative and complete.']);
                }
            }
            if ($noncashFx &&
                (! BigDecimal::of((string) $item['debit'])->isZero()
                    || ! BigDecimal::of((string) $item['credit'])->isZero())) {
                throw ValidationException::withMessages(['journal' => 'Foreign exchange revaluation cannot create source-currency bank movements.']);
            }
            $dr = $math->money((string) $item['debit']);
            $cr = $math->money((string) $item['credit']);
            $bdr = $math->money((string) $item['base_debit']);
            $bcr = $math->money((string) $item['base_credit']);
            if (($dr->isGreaterThan(0) && $cr->isGreaterThan(0))
                || ($bdr->isGreaterThan(0) && $bcr->isGreaterThan(0))) {
                throw ValidationException::withMessages(['journal' => 'A journal line cannot be both a debit and a credit.']);
            }
            if ($dr->isZero() && $cr->isZero()
                && ! $noncashFx
                && ! ($type === 'register_entry' && ($entry->kind === 'asset_disposal' || ($entry->kind === 'register_reversal' && ($entry->data_snapshot['details']['kind'] ?? '') === 'asset_disposal')) && in_array($key, ['asset_cost', 'asset_accumulated', 'asset_disposal_gain', 'asset_disposal_loss'], true))
                && ! in_array($key, ['fx_gain', 'fx_loss', 'unrealized_fx_gain', 'unrealized_fx_loss'], true)) {
                throw ValidationException::withMessages(['journal' => 'Base-only journal lines are limited to currency gains and losses.']);
            }
            if (($dr->isZero() && $cr->isZero() && $bdr->isZero() && $bcr->isZero())) {
                throw ValidationException::withMessages(['journal' => 'Zero-amount journal lines are not allowed.']);
            }
            $debit = $debit->plus($dr);
            $credit = $credit->plus($cr);
            $baseDebit = $baseDebit->plus($bdr);
            $baseCredit = $baseCredit->plus($bcr);
        }
        if (($noncashFx
                ? (! $debit->isZero() || ! $credit->isZero())
                : $debit->isLessThanOrEqualTo(0))
            || ! $debit->isEqualTo($credit)
            || $baseDebit->isLessThanOrEqualTo(0) || ! $baseDebit->isEqualTo($baseCredit)) {
            throw ValidationException::withMessages([
                'journal' => 'Financial journal must balance in both document and base currency.',
            ]);
        }
        foreach ($accounts as $key => $account) {
            if ($account->type === 'asset' && (str_starts_with($key, 'cash_') || CashCustody::query()->where('business_id', $business->id)->where('account_id', $account->id)->exists())) {
                $balance = BigDecimal::zero();
                foreach (FinanceJournalLine::query()->where('business_id', $business->id)->where('account_id', $account->id)->get(['debit', 'credit']) as $prior) {
                    $balance = $balance->plus((string) $prior->debit)->minus((string) $prior->credit);
                }foreach ($lines as $line) {
                    if ($line['account'] === $key) {
                        $balance = $balance->plus((string) $line['debit'])->minus((string) $line['credit']);
                    }
                }abort_unless(! $balance->isLessThan(0), 422, 'This voucher would spend more than the actual posted cash float.');
            }
        }
        if (FinanceJournal::query()->where('business_id', $business->id)
            ->where('source_type', $type)->where('source_id', $sourceId)->exists()) {
            abort(422, 'This document has already been posted.');
        }
        $journal = FinanceJournal::create([
            'business_id' => $business->id, 'reference' => 'JRN-'.Str::upper((string) Str::ulid()),
            'source_type' => $type, 'source_id' => $sourceId,
            'posted_on' => $postedOn, 'currency' => $currency, 'base_currency' => $business->base_currency,
            'rate_to_base' => $rate, 'description' => $description,
            'created_by' => $postedBy, 'approved_by' => $approvedBy,
        ]);
        foreach ($lines as $item) {
            $account = $accounts[$item['account']];
            FinanceJournalLine::create([
                'business_id' => $business->id, 'journal_id' => $journal->id,
                'account_id' => $account->id, 'debit' => (string) $math->money((string) $item['debit']),
                'credit' => (string) $math->money((string) $item['credit']),
                'base_debit' => (string) $math->money((string) $item['base_debit']),
                'base_credit' => (string) $math->money((string) $item['base_credit']),
                'memo' => $item['memo'] ?? null,
            ]);
        }

        $evidence = app(FinanceManagementReports::class)->journalEvidence($journal);
        $journal->update(['source_snapshot' => $evidence, 'source_hash' => app(FinanceWorkflows::class)->hash($evidence)]);
        if ($cashProof) {
            app(FinanceCashCarrying::class)->record($journal, $cashProof);
        }

        return $journal;
    }

    public function line(
        string $account, string $debit, string $credit, string $baseDebit, string $baseCredit,
        ?string $memo = null,
    ): array {
        return [
            'account' => $account, 'debit' => $debit, 'credit' => $credit,
            'base_debit' => $baseDebit, 'base_credit' => $baseCredit, 'memo' => $memo,
        ];
    }
}
