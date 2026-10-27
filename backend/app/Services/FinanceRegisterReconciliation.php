<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceRegisterEntry;
use Brick\Math\BigDecimal;

class FinanceRegisterReconciliation
{
    public function report(Business $business, string $through, array $ledgerBalances): array
    {
        $keys = ['loans_receivable' => 'Principal owed to the business', 'loan_interest_receivable' => 'Lending interest receivable', 'loan_fees_receivable' => 'Earned lending fees receivable', 'loan_fees_payable' => 'Accrued lender fees', 'loan_deferred_cost' => 'Deferred borrowing cost', 'asset_cost' => 'Equipment cost', 'asset_accumulated' => 'Accumulated depreciation', 'asset_payable' => 'Equipment payable', 'loan_principal' => 'External borrowing principal', 'owner_borrowing' => 'Owner borrowing principal', 'loan_interest_payable' => 'Accrued borrowing interest'];
        $expected = array_fill_keys(array_keys($keys), BigDecimal::zero());
        $invalid = 0;
        foreach (FinanceRegisterEntry::query()->where('business_id', $business->id)->whereIn('posting_status', ['posted', 'reversed'])->whereDate('event_on', '<=', $through)->orderBy('id')->cursor() as $entry) {
            $snapshot = $entry->data_snapshot;
            if (! hash_equals($entry->snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) {
                $invalid++;

                continue;
            }
            foreach ($snapshot['lines'] as $line) {
                if (isset($keys[$line['account']])) {
                    $expected[$line['account']] = $expected[$line['account']]->plus($line['base_debit'])->minus($line['base_credit']);
                }
            }
        }
        $rows = [];
        foreach ($keys as $key => $label) {
            $actual = $ledgerBalances[$key] ?? BigDecimal::zero();
            $rows[] = ['key' => $key, 'label' => $label, 'source' => $expected[$key], 'ledger' => $actual, 'difference' => $actual->minus($expected[$key])];
        }

        return ['rows' => $rows, 'invalid_sources' => $invalid, 'matches' => $invalid === 0 && collect($rows)->every(fn ($r) => $r['difference']->isZero())];
    }
}
