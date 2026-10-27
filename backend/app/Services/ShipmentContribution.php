<?php

namespace App\Services;

use App\Models\ExportShipment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceTradeCredit;
use App\Models\GoodsReceipt;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShipmentContribution
{
    /**
     * Provisional management estimate only. No COGS or waste overhead journal
     * is posted here, and missing source costs block a contribution figure.
     */
    public function report(ExportShipment $shipment): array
    {
        $shipment->loadMissing([
            'business', 'customer', 'commercialInvoice', 'allocations.carton.packingJob.product',
            'allocations.carton.contents.batch',
        ]);
        $base = $shipment->business->base_currency;
        $math = app(FinanceMath::class);
        $salesJournal = null;
        $salesRevenue = null;
        $invoice = $shipment->commercialInvoice;
        if ($invoice && $invoice->posting_status === 'posted') {
            $salesJournal = FinanceJournal::query()->where('business_id', $shipment->business_id)
                ->where('source_type', 'commercial_invoice')
                ->where('source_id', $invoice->id)->with('lines.account')->first();
            if ($salesJournal) {
                $salesRevenue = BigDecimal::zero();
                foreach ($salesJournal->lines as $line) {
                    if ($line->account?->system_key === 'export_sales') {
                        $salesRevenue = $salesRevenue->plus((string) $line->base_credit)
                            ->minus((string) $line->base_debit);
                    }
                }
            }
        }

        $purchaseCosts = BigDecimal::zero();
        $lines = [];
        $missing = [];
        $receiptCache = [];
        $billCache = [];
        $journalCache = [];
        foreach ($shipment->allocations as $allocation) {
            $carton = $allocation->carton;
            if (! $carton || ! $carton->packingJob) {
                $missing[] = 'A carton has no packing job.';

                continue;
            }
            $product = $carton->packingJob->product;
            foreach ($carton->contents as $content) {
                $batchId = $content->batch_id;
                if (! array_key_exists($batchId, $receiptCache)) {
                    $receiptCache[$batchId] = GoodsReceipt::query()
                        ->where('business_id', $shipment->business_id)
                        ->where('batch_id', $batchId)->with('purchaseOrder')->first();
                }
                $receipt = $receiptCache[$batchId];
                $baseCost = null;
                $purchase = null;
                $bill = null;
                $purchase = $receipt?->purchaseOrder;
                try {
                    $basis = app(FinanceCostBasis::class)->batchBasis($content->batch);
                    $baseCost = $math->money(BigDecimal::of((string) $content->quantity)->multipliedBy($basis['unit']));
                    $purchaseCosts = $purchaseCosts->plus($baseCost);
                } catch (ValidationException $error) {
                    $missing[] = $carton->carton_code.': '.collect($error->errors())->flatten()->implode(' ');
                } catch (HttpException $error) {
                    if ($error->getStatusCode() !== 422) {
                        throw $error;
                    }
                    $missing[] = $carton->carton_code.': '.$error->getMessage();
                }
                $lines[] = [
                    'carton' => $carton->carton_code, 'batch' => $content->batch?->batch_code,
                    'product' => $product?->name, 'quantity' => $content->quantity,
                    'unit' => $product?->base_unit, 'purchase' => $purchase?->reference,
                    'purchase_currency' => $purchase?->currency,
                    'base_cost' => $baseCost === null ? null : (string) $baseCost,
                ];
            }
        }

        $costBasis = 'current reviewed source estimate';
        if (FinanceInventoryRelief::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->where('posting_status', 'posted')->exists()) {
            $purchaseCosts = app(FinanceManagementReports::class)->shipmentCost($shipment);
            $costBasis = 'posted shipment cost, late allocations and original-cost returns';
        }
        if ($invoice && $salesRevenue !== null) {
            foreach (FinanceCreditNote::query()->where('business_id', $shipment->business_id)->where('commercial_invoice_id', $invoice->id)->where('posting_status', 'posted')->cursor() as $credit) {
                $journal = FinanceJournal::query()->where('business_id', $shipment->business_id)->where('source_type', 'credit_note')->where('source_id', $credit->id)->with('lines.account')->firstOrFail();
                foreach ($journal->lines as $line) {
                    if ($line->account->system_key === 'export_sales') {
                        $salesRevenue = $salesRevenue->plus((string) $line->base_credit)->minus((string) $line->base_debit);
                    }
                }
            }
            foreach (FinanceTradeCredit::query()->where('business_id', $shipment->business_id)->where('commercial_invoice_id', $invoice->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->with('workflow.legs')->cursor() as $credit) {
                foreach ($credit->workflow->legs as $leg) {
                    foreach ($leg->lines as $line) {
                        if ($line['account'] === 'sales_returns') {
                            $salesRevenue = $salesRevenue->minus($line['base_debit'])->plus($line['base_credit']);
                        }
                    }
                }
            }
        }
        $costItems = FinanceShipmentCost::query()->where('business_id', $shipment->business_id)
            ->where('shipment_id', $shipment->id)->orderBy('id')->get();
        $postedCosts = BigDecimal::zero();
        $expenses = [];
        foreach ($costItems as $cost) {
            $baseAmount = null;
            if ($cost->status === 'posted') {
                $journal = FinanceJournal::query()->where('business_id', $shipment->business_id)
                    ->where('source_type', 'shipment_cost')
                    ->where('source_id', $cost->id)->first();
                if ($journal) {
                    $baseAmount = $math->base((string) $cost->amount, (string) $journal->rate_to_base);
                    foreach (FinancePartialBankReturn::query()
                        ->where('business_id', $shipment->business_id)
                        ->where('source_type', 'shipment_cost')->where('source_id', $cost->id)
                        ->where('posting_status', 'posted')->get() as $return) {
                        $partialJournal = FinanceJournal::query()->where('business_id', $shipment->business_id)
                            ->where('source_type', 'partial_bank_return')
                            ->where('source_id', $return->id)->with('lines.account')->firstOrFail();
                        foreach ($partialJournal->lines as $line) {
                            if ($line->account?->system_key === 'shipment_cost') {
                                $baseAmount = $baseAmount->plus((string) $line->base_debit)
                                    ->minus((string) $line->base_credit);
                            }
                        }
                    }
                    $postedCosts = $postedCosts->plus($baseAmount);
                } else {
                    $missing[] = $cost->reference.': posted cost is missing its journal.';
                }
            } else {
                $missing[] = $cost->reference.': shipment cost has not been posted.';
            }
            $expenses[] = [
                'reference' => $cost->reference, 'category' => $cost->category,
                'description' => $cost->description, 'status' => $cost->status,
                'amount' => $cost->amount, 'currency' => $cost->currency,
                'base_amount' => $baseAmount === null ? null : (string) $baseAmount,
            ];
        }
        if (! $salesJournal) {
            $missing[] = 'Commercial invoice revenue has not been recognised in the ledger.';
        }
        if ($shipment->allocations->isEmpty()) {
            $missing[] = 'No cartons have been allocated.';
        }
        $missing = array_values(array_unique($missing));
        $estimate = $salesRevenue !== null && $missing === []
            ? $salesRevenue->minus($purchaseCosts)->minus($postedCosts) : null;

        return [
            'base_currency' => $base, 'cost_basis' => $costBasis,
            'revenue' => $salesRevenue === null ? null : (string) $salesRevenue,
            'acquisition_cost' => (string) $purchaseCosts,
            'operating_cost' => (string) $postedCosts,
            'provisional_contribution' => $estimate === null ? null : (string) $estimate,
            'missing' => $missing, 'source_lines' => $lines, 'expenses' => $expenses,
        ];
    }
}
