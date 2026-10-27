<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class FinanceSettledCarrying
{
    public function portion(Business $business, CommercialInvoice|FinanceSupplierBill $document, BigDecimal $amount, string $date): array
    {
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $remaining = app(FinanceDocuments::class)->outstanding($document, false);
        abort_unless($remaining->isGreaterThan(0) && ! $amount->isGreaterThan($remaining), 422, 'Document has insufficient outstanding balance.');
        $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', $document instanceof CommercialInvoice ? 'commercial_invoice' : 'supplier_bill')->where('source_id', $document->id)->firstOrFail();
        abort_unless($date >= $journal->posted_on->toDateString(), 422, 'An allocation cannot precede its document journal.');
        if ($document->currency === $business->base_currency) {
            return ['base' => $amount, 'reclass' => []];
        }
        $position = app(FinanceMonetaryRevaluations::class)->position($business, $document instanceof CommercialInvoice ? 'commercial_invoice' : 'supplier_bill', $document->id);
        abort_unless($date >= $position['latest_journal_on'], 422, 'An allocation cannot precede the latest valuation journal.');
        $portion = fn (string $value) => $amount->isEqualTo($remaining) ? $math->money($value) : $math->money(BigDecimal::of($value)->multipliedBy($amount)->dividedBy($remaining, 14, RoundingMode::HalfUp));
        $base = $portion($position['previous_base']);
        $unrealized = $portion($position['unrealized_open']);
        $reclass = [];
        if ($unrealized->isGreaterThan(0)) {
            $reclass[] = $ledger->line('unrealized_fx_gain', '0', '0', (string) $unrealized, '0', 'Release settled unrealised FX gain');
            $reclass[] = $ledger->line('fx_gain', '0', '0', '0', (string) $unrealized, 'Recognise settled FX gain');
        } elseif ($unrealized->isLessThan(0)) {
            $reclass[] = $ledger->line('unrealized_fx_loss', '0', '0', '0', (string) $unrealized->abs(), 'Release settled unrealised FX loss');
            $reclass[] = $ledger->line('fx_loss', '0', '0', (string) $unrealized->abs(), '0', 'Recognise settled FX loss');
        }

        return compact('base','reclass');
    }
}
