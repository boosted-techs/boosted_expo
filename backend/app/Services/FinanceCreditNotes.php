<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceCreditNotes
{
    public function ensureNoPayments(CommercialInvoice $invoice): void
    {
        app(FinanceTradeCredits::class)->assertNoCredit($invoice->business_id, 'commercial_invoice_id', $invoice->id);
        if (FinanceOperation::query()->where('business_id', $invoice->business_id)->where('commercial_invoice_id', $invoice->id)->whereIn('posting_status', ['not_posted', 'posted'])->exists()) {
            throw ValidationException::withMessages(['invoice' => 'A deposit is allocated to this invoice. Resolve the allocation before a full credit.']);
        }
        if (FinancePayment::query()->where('business_id', $invoice->business_id)
            ->where('commercial_invoice_id', $invoice->id)
            ->whereIn('status', ['pending', 'posted'])->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Customer payments already exist. Reconcile and use a controlled refund or partial-credit process before correcting this invoice.']);
        }
    }

    public function prepare(Business $business, CommercialInvoice $invoice,
        string $reason, int $actor): FinanceCreditNote
    {
        return DB::transaction(function () use ($business, $invoice, $reason, $actor) {
            $invoice = CommercialInvoice::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->posting_status === 'posted', 422,
                'This workflow creates a full credit only for a posted commercial invoice. Unposted corrections need a separate cancellation procedure.');
            abort_unless(! FinanceCreditNote::query()->where('business_id', $business->id)
                ->where('commercial_invoice_id', $invoice->id)->exists(), 422,
                'This invoice already has a credit-note correction record.');
            $this->ensureNoPayments($invoice);
            abort_unless(! FinanceBalanceAdjustment::query()
                ->where('business_id', $business->id)
                ->where('commercial_invoice_id', $invoice->id)->exists(), 422,
                'An existing partial customer adjustment must be resolved before issuing a full credit.');
            $original = FinanceJournal::query()->where('business_id', $business->id)
                ->where('source_type', 'commercial_invoice')
                ->where('source_id', $invoice->id)->firstOrFail();
            $data = $invoice->data_snapshot;

            return FinanceCreditNote::create([
                'business_id' => $business->id,
                'commercial_invoice_id' => $invoice->id,
                'reference' => 'CN-'.Str::upper((string) Str::ulid()),
                'currency' => $invoice->currency, 'amount' => $invoice->grand_total,
                'reason' => $reason, 'posting_status' => 'not_posted',
                'data_snapshot' => [
                    'invoice_reference' => $invoice->reference,
                    'invoice_sha256' => $invoice->sha256,
                    'original_journal' => $original->reference,
                    'original_journal_id' => $original->id,
                    'original_rate' => (string) $original->rate_to_base,
                    'business' => $data['business'] ?? ['name' => $business->name],
                    'customer' => $data['customer'] ?? [],
                    'amounts' => [
                        'grand_total' => (string) $invoice->grand_total,
                        'subtotal' => (string) $invoice->subtotal,
                        'tax' => (string) $invoice->tax_amount,
                        'currency' => $invoice->currency,
                    ],
                ],
                'created_by' => $actor,
            ]);
        }, 3);
    }

    public function assertEligible(FinanceCreditNote $note): FinanceJournal
    {
        $invoice = CommercialInvoice::query()->where('business_id', $note->business_id)
            ->lockForUpdate()->findOrFail($note->commercial_invoice_id);
        abort_unless($invoice->posting_status === 'posted', 422,
            'Original invoice must remain posted until the credit is approved.');
        $this->ensureNoPayments($invoice);
        abort_unless(! FinanceBalanceAdjustment::query()
            ->where('business_id', $note->business_id)
            ->where('commercial_invoice_id', $invoice->id)->exists(), 422,
            'An existing partial adjustment conflicts with the full credit.');

        return FinanceJournal::query()->where('business_id', $note->business_id)
            ->where('source_type', 'commercial_invoice')->where('source_id', $invoice->id)
            ->with('lines.account')->firstOrFail();
    }

    public function lines(FinanceCreditNote $note): array
    {
        $original = $this->assertEligible($note);
        $lines = [];
        foreach ($original->lines as $line) {
            $key = $line->account->system_key;
            $lines[] = app(FinanceLedger::class)->line(
                $key, (string) $line->credit, (string) $line->debit,
                (string) $line->base_credit, (string) $line->base_debit,
                'Full credit against '.$note->data_snapshot['invoice_reference'],
            );
        }

        return $lines;
    }

    public function finalize(FinanceCreditNote $note): void
    {
        $invoice = CommercialInvoice::query()->where('business_id', $note->business_id)
            ->lockForUpdate()->findOrFail($note->commercial_invoice_id);
        $note->update(['posting_status' => 'posted', 'issued_at' => now()]);
        $invoice->update(['posting_status' => 'credited']);
        $invoice->accountingHandoff()->update(['posting_status' => 'credited']);
        $note->refresh()->load('invoice');
        $html = view('finance.credit-print', ['note' => $note])->render();
        $note->update(['html_snapshot' => $html, 'sha256' => hash('sha256', $html)]);
    }
}
