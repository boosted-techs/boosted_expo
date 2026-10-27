<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CartonContent;
use App\Models\CommercialInvoice;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\PackingWasteEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceBalanceAdjustments
{
    public function prepare(Business $business, string $kind, int $id,
        string $amount, string $reason, int $actor): FinanceBalanceAdjustment
    {
        return DB::transaction(function () use ($business, $kind, $id, $amount, $reason, $actor) {
            abort_unless(in_array($kind, ['customer', 'supplier'], true), 422);
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $document = $this->document($business->id, $kind, $id);
            $this->eligible($business, $document, $kind);
            if (FinanceBalanceAdjustment::query()->where('business_id', $business->id)
                ->where($kind === 'customer' ? 'commercial_invoice_id' : 'supplier_bill_id', $id)->exists()) {
                throw ValidationException::withMessages(['adjustment' => 'A concession already exists for this document. Partial adjustments cannot be repeated in this release.']);
            }
            $value = app(FinanceMath::class)->money($amount);
            $total = BigDecimal::of((string) ($kind === 'customer'
                ? $document->grand_total : $document->total));
            if ($value->isLessThanOrEqualTo(0) || $value->isGreaterThanOrEqualTo($total)) {
                throw ValidationException::withMessages(['amount' => 'A partial concession must be positive and less than the document total. For a complete invoice cancellation use the full credit-note workflow.']);
            }
            $original = $this->original($document, $kind);
            $snapshot = [
                'kind' => $kind, 'document_id' => $id, 'document_reference' => $document->reference,
                'currency' => $document->currency, 'original_total' => (string) $total,
                'original_rate' => (string) $original->rate_to_base,
                'original_journal_id' => $original->id,
                'amount' => (string) $value,
            ];

            return FinanceBalanceAdjustment::create([
                'business_id' => $business->id,
                'reference' => 'ADJ-'.Str::upper((string) Str::ulid()),
                'kind' => $kind,
                'commercial_invoice_id' => $kind === 'customer' ? $id : null,
                'supplier_bill_id' => $kind === 'supplier' ? $id : null,
                'currency' => $document->currency, 'amount' => (string) $value,
                'reason' => $reason, 'data_snapshot' => $snapshot,
                'sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'posting_status' => 'not_posted', 'created_by' => $actor,
            ]);
        }, 3);
    }

    public function document(int $businessId, string $kind, int $id): CommercialInvoice|FinanceSupplierBill
    {
        $model = $kind === 'customer' ? CommercialInvoice::class : FinanceSupplierBill::class;

        return $model::query()->where('business_id', $businessId)->lockForUpdate()->findOrFail($id);
    }

    private function original(CommercialInvoice|FinanceSupplierBill $document,
        string $kind): FinanceJournal
    {
        return FinanceJournal::query()->where('business_id', $document->business_id)
            ->where('source_type', $kind === 'customer' ? 'commercial_invoice' : 'supplier_bill')
            ->where('source_id', $document->id)->firstOrFail();
    }

    public function eligible(Business $business,
        CommercialInvoice|FinanceSupplierBill $doc, string $kind): void
    {
        abort_unless($doc->business_id === $business->id &&
            $doc->posting_status === 'posted', 422, 'Only posted financial documents can be adjusted.');
        $column = $kind === 'customer' ? 'commercial_invoice_id' : 'supplier_bill_id';
        app(FinanceTradeCredits::class)->assertNoCredit($business->id, $column, $doc->id);
        if (FinanceOperation::query()->where('business_id', $business->id)->where($column, $doc->id)->whereIn('posting_status', ['not_posted', 'posted'])->exists()) {
            throw ValidationException::withMessages(['adjustment' => 'A deposit or advance is allocated to this document. Resolve it before a price concession.']);
        }
        if (FinancePayment::query()->where('business_id', $business->id)
            ->where($column, $doc->id)->whereIn('status', ['pending', 'posted'])->exists()) {
            throw ValidationException::withMessages(['adjustment' => 'A pending or posted payment already exists. Apply a controlled settlement/credit process instead.']);
        }
        if ($kind === 'customer') {
            if (BigDecimal::of((string) $doc->tax_amount)->isGreaterThan(0)) {
                throw ValidationException::withMessages(['adjustment' => 'Tax-bearing invoices require reviewed VAT/tax allocation; only zero-tax concessions are supported.']);
            }
            if (FinanceCreditNote::query()->where('business_id', $business->id)
                ->where('commercial_invoice_id', $doc->id)->exists()) {
                throw ValidationException::withMessages(['adjustment' => 'This invoice has a full credit-note request.']);
            }
        } else {
            $batchIds = GoodsReceipt::query()->where('business_id', $business->id)
                ->where('purchase_order_id', $doc->purchase_order_id)
                ->whereNotNull('batch_id')->pluck('batch_id')->all();
            if ($batchIds !== [] &&
                (CartonContent::query()->where('business_id', $business->id)
                    ->whereIn('batch_id', $batchIds)->exists()
                 || PackingWasteEntry::query()->where('business_id', $business->id)
                     ->whereIn('batch_id', $batchIds)->exists())) {
                throw ValidationException::withMessages(['adjustment' => 'Part of this produce has been packed or lost. Revalue inventory and COGS before allowing a supplier price adjustment.']);
            }
        }
    }

    public function lines(FinanceBalanceAdjustment $adjustment): array
    {
        $snapshot = $adjustment->data_snapshot;
        abort_unless(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))
            === $adjustment->sha256 && $adjustment->kind === $snapshot['kind']
            && $adjustment->currency === $snapshot['currency']
            && BigDecimal::of((string) $adjustment->amount)->isEqualTo($snapshot['amount']),
            422, 'The original adjustment snapshot is inconsistent.');
        $business = Business::query()->findOrFail($adjustment->business_id);
        $kind = $adjustment->kind;
        $doc = $this->document($business->id, $kind,
            $kind === 'customer' ? $adjustment->commercial_invoice_id : $adjustment->supplier_bill_id);
        $this->eligible($business, $doc, $kind);
        $original = $this->original($doc, $kind);
        abort_unless($original->id === $snapshot['original_journal_id']
            && (string) $original->rate_to_base === (string) $snapshot['original_rate'], 422,
            'The original posting rate or journal has changed.');
        $base = app(FinanceMath::class)->base((string) $adjustment->amount, (string) $original->rate_to_base);
        $ledger = app(FinanceLedger::class);

        return $kind === 'customer' ? [
            $ledger->line('export_sales', (string) $adjustment->amount, '0', (string) $base, '0',
                'Approved partial commercial concession'),
            $ledger->line('receivable', '0', (string) $adjustment->amount, '0', (string) $base,
                'Decrease customer balance'),
        ] : [
            $ledger->line('payable', (string) $adjustment->amount, '0', (string) $base, '0',
                'Approved supplier price concession'),
            $ledger->line('inventory', '0', (string) $adjustment->amount, '0', (string) $base,
                'Decrease received inventory value before consumption'),
        ];
    }
}
