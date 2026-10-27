<?php

namespace App\Services;

use App\Models\ExportShipment;
use App\Models\FinanceJournal;
use App\Models\FinancePartialBankReturn;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
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
                if (! $receipt || ! $receipt->purchaseOrder) {
                    $missing[] = $carton->carton_code.': source batch has no purchase receipt.';
                } else {
                    $purchase = $receipt->purchaseOrder;
                    if (! array_key_exists($purchase->id, $billCache)) {
                        $billCache[$purchase->id] = FinanceSupplierBill::query()
                            ->where('business_id', $shipment->business_id)
                            ->where('purchase_order_id', $purchase->id)
                            ->where('posting_status', 'posted')->first();
                    }
                    $bill = $billCache[$purchase->id];
                    if (! $bill) {
                        $missing[] = $purchase->reference.': source purchase bill has not been posted.';
                    } else {
                        if (! array_key_exists($bill->id, $journalCache)) {
                            $journalCache[$bill->id] = FinanceJournal::query()
                                ->where('business_id', $shipment->business_id)
                                ->where('source_type', 'supplier_bill')
                                ->where('source_id', $bill->id)->first();
                        }
                        $journal = $journalCache[$bill->id];
                        if (! $journal) {
                            $missing[] = $bill->reference.': missing posted supplier-bill journal.';
                        } else {
                            try {
                                $unit = app(FinanceCostBasis::class)->receiptUnitBase($receipt);
                                $baseCost = $math->money(BigDecimal::of((string) $content->quantity)->multipliedBy($unit));
                                $purchaseCosts = $purchaseCosts->plus($baseCost);
                            } catch (ValidationException $error) {
                                $missing[] = $purchase->reference.': '.collect($error->errors())->flatten()->implode(' ');
                            } catch (HttpException $error) {
                                if ($error->getStatusCode() !== 422) {
                                    throw $error;
                                }
                                $missing[] = $purchase->reference.': '.$error->getMessage();
                            }
                        }
                    }
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
            'base_currency' => $base,
            'revenue' => $salesRevenue === null ? null : (string) $salesRevenue,
            'acquisition_cost' => (string) $purchaseCosts,
            'operating_cost' => (string) $postedCosts,
            'provisional_contribution' => $estimate === null ? null : (string) $estimate,
            'missing' => $missing, 'source_lines' => $lines, 'expenses' => $expenses,
        ];
    }
}
