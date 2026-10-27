<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankProfile;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceTransactionReversal;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

class FinanceBankReturns
{
    /**
     * A confirmed, originally reconciled transfer must have a separate
     * opposite bank statement line BEFORE its accounting correction is posted.
     * The file is user-uploaded evidence, never authenticated by a bank API.
     */
    public function evidence(
        Business $business, string $type, int $sourceId, int $accountId,
        string $currency, string $amount, ?int $returnLineId, ?int $excludeReversalId = null,
        bool $allowPartial = false, ?int $excludePartialId = null
    ): ?array {
        $matches = FinanceBankMatch::query()->where('business_id', $business->id)
            ->where('source_type', $type)->where('source_id', $sourceId)
            ->whereIn('status', ['pending', 'confirmed'])->get();
        if ($matches->isEmpty()) {
            if ($returnLineId !== null) {
                throw ValidationException::withMessages([
                    'return_bank_line_id' => 'A bank-return line is only used when the original transaction has a confirmed bank match.',
                ]);
            }

            return null;
        }
        if ($matches->count() !== 1 || $matches[0]->status !== 'confirmed') {
            throw ValidationException::withMessages(['reversal' => 'Complete independent matching of the original payment before requesting its bank return.']);
        }
        $match = $matches[0];
        $originalLine = FinanceBankStatementLine::query()->where('business_id', $business->id)
            ->with('statement')->findOrFail($match->statement_line_id);
        $originalStatement = $originalLine->statement;
        if ($originalStatement->status !== 'reconciled'
            || $originalStatement->account_id !== $accountId
            || $originalStatement->currency !== $currency) {
            throw ValidationException::withMessages(['reversal' => 'The original bank statement must be fully reconciled against the same currency and bank account.']);
        }
        // The generic legacy bank account is historically mixed-currency, so
        // it has no safe independent currency-specific bank return mechanism.
        if (! FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('account_id', $accountId)->where('currency', $currency)
            ->where('active', true)->exists()) {
            throw ValidationException::withMessages(['reversal' => 'Bank-matched return corrections require a named bank account with a fixed currency.']);
        }
        if ($returnLineId === null) {
            throw ValidationException::withMessages([
                'return_bank_line_id' => 'Import the actual opposite-direction bank return before proposing a correction to an already reconciled payment.',
            ]);
        }
        $return = FinanceBankStatementLine::query()->where('business_id', $business->id)
            ->with('statement')->findOrFail($returnLineId);
        $statement = $return->statement;
        $expected = $originalLine->direction === 'credit' ? 'debit' : 'credit';
        if ($statement->account_id !== $accountId
            || $statement->currency !== $currency || $statement->status !== 'open'
            || $return->direction !== $expected
            || ($allowPartial
                ? BigDecimal::of((string) $return->amount)->isGreaterThan($amount)
                : ! BigDecimal::of((string) $return->amount)->isEqualTo($amount))
            || $return->transaction_on->toDateString()
                < $originalLine->transaction_on->toDateString()
            || $return->transaction_on->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages(['return_bank_line_id' => 'The imported return must be an open statement line on the same named account and currency, with the opposite direction, exact amount and valid later date.']);
        }
        abort_if(app(FinanceMoneyCorrections::class)->bankReservation($business->id, $return->id), 422, 'This bank return is reserved for a documented money correction.');
        if (FinanceBankMatch::query()->where('business_id', $business->id)
            ->where('statement_line_id', $return->id)->whereIn('status', ['pending', 'confirmed'])
            ->exists()) {
            throw ValidationException::withMessages(['return_bank_line_id' => 'This bank return has already been matched or reserved for another financial transaction.']);
        }
        if (FinanceRegisterEntry::query()->where('business_id', $business->id)
            ->where('return_bank_line_id', $return->id)
            ->whereIn('posting_status', ['not_posted', 'posted', 'reversed'])->exists()) {
            throw ValidationException::withMessages(['return_bank_line_id' => 'This bank return is reserved for an asset or borrowing correction.']);
        }
        if (FinancePartialBankReturn::query()->where('business_id', $business->id)
            ->where('return_bank_line_id', $return->id)
            ->when($excludePartialId !== null, fn ($q) => $q->where('id', '!=', $excludePartialId))
            ->exists()) {
            throw ValidationException::withMessages(['return_bank_line_id' => 'This bank return is already reserved for another partial settlement.']);
        }
        if (FinanceTransactionReversal::query()->where('business_id', $business->id)
            ->where('return_bank_line_id', $return->id)
            ->when($excludeReversalId !== null, fn ($q) => $q->where('id', '!=', $excludeReversalId))
            ->exists()) {
            throw ValidationException::withMessages(['return_bank_line_id' => 'This imported bank return line is already reserved for an existing correction.']);
        }

        return [
            'original_match_id' => $match->id,
            'original_match_confirmed_at' => $match->confirmed_at?->toIso8601String(),
            'original_line_id' => $originalLine->id,
            'original_bank_reference' => $originalLine->bank_reference,
            'original_statement_id' => $originalStatement->id,
            'original_statement_sha256' => $originalStatement->file_sha256,
            'original_transaction_on' => $originalLine->transaction_on->toDateString(),
            'return_line_id' => $return->id,
            'return_statement_id' => $statement->id,
            'return_statement_sha256' => $statement->file_sha256,
            'return_bank_reference' => $return->bank_reference,
            'return_description' => $return->description,
            'return_transaction_on' => $return->transaction_on->toDateString(),
            'return_amount' => (string) $return->amount,
            'return_direction' => $return->direction,
        ];
    }
}
