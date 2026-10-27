<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Models\InvoiceAccountingHandoff;
use App\Models\SalesOrder;
use App\Models\ShipmentEvent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommercialInvoiceIssuance
{
    public function issue(ExportShipment $shipment, array $data, int $userId): CommercialInvoice
    {
        return DB::transaction(function () use ($shipment, $data, $userId) {
            Business::query()->whereKey($shipment->business_id)->lockForUpdate()->firstOrFail();
            $shipment = ExportShipment::query()->where('business_id', $shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->id);
            abort_unless(in_array($shipment->status, ['ready', 'dispatched', 'delivered'], true), 422,
                'Mark the shipment ready before issuing a commercial invoice.');
            abort_unless($shipment->sales_order_id !== null, 422,
                'Link this shipment to a confirmed sales order first.');
            $order = SalesOrder::query()->where('business_id', $shipment->business_id)
                ->lockForUpdate()->findOrFail($shipment->sales_order_id);
            abort_unless(in_array($order->status, ['confirmed', 'closed'], true) && $shipment->customer_id === $order->customer_id,
                422, 'The shipment and confirmed order must belong to the same customer.');
            abort_unless(! CommercialInvoice::query()->where('business_id', $shipment->business_id)
                ->where('shipment_id', $shipment->id)->exists(), 422,
                'A commercial invoice is already issued for this shipment. Amendments require a controlled credit/reissue workflow.');

            $shipment->load(['business', 'customer', 'allocations.carton.packingJob.product',
                'allocations.carton.contents.batch']);
            abort_unless($shipment->allocations->isNotEmpty(), 422, 'The shipment has no cartons.');
            app(SalesFulfillment::class)->assertCapacity($order, [], true);
            abort_unless($order->pricing_status === 'accepted' && $order->sales_quotation_id && $order->grand_total !== null, 422, 'Agree selling prices before issuing a commercial invoice.');
            $order->load('lines.product');

            $packed = [];
            $cartons = [];
            $net = BigDecimal::zero();
            $gross = BigDecimal::zero();
            foreach ($shipment->allocations as $allocation) {
                $carton = $allocation->carton;
                abort_unless($carton && $carton->packingJob && $carton->contents->isNotEmpty(), 422,
                    'Allocated carton is missing packing records.');
                $productId = $carton->packingJob->product_id;
                foreach ($carton->contents as $content) {
                    $packed[$productId] = ($packed[$productId] ?? BigDecimal::zero())
                        ->plus((string) $content->quantity);
                }
                $net = $net->plus((string) $carton->net_weight_kg);
                $gross = $gross->plus((string) $carton->gross_weight_kg);
                $cartons[] = [
                    'reference' => $carton->carton_code,
                    'product' => $carton->packingJob->product->name,
                    'net_weight_kg' => $carton->net_weight_kg,
                    'gross_weight_kg' => $carton->gross_weight_kg,
                    'batch_codes' => $carton->contents->pluck('batch.batch_code')->filter()->unique()->values()->all(),
                ];
            }
            $priced = [];
            foreach ($order->lines as $line) {
                $quantity = $packed[$line->product_id] ?? BigDecimal::zero();
                if ($quantity->isLessThanOrEqualTo(0)) {
                    continue;
                }
                if ($quantity->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                    throw ValidationException::withMessages(['invoice' => 'Shipment quantity exceeds the confirmed sales order.']);
                }
                $priced[] = [
                    'product_id' => $line->product_id, 'sku' => $line->product_sku ?? $line->product->sku,
                    'product' => $line->product_name ?? $line->product->name,
                    'unit' => $line->unit ?? $line->product->base_unit,
                    'quantity' => (string) $quantity, 'unit_price' => (string) $line->unit_price,
                ];
                unset($packed[$line->product_id]);
            }
            if ($priced === [] || $packed !== []) {
                throw ValidationException::withMessages(['invoice' => 'Cartons contain products not priced on the sales order.']);
            }
            $totals = app(SalesPricing::class)->totals($priced, (string) $order->discount_percent,
                (string) $order->tax_percent, (string) $data['freight_amount'], (string) $data['insurance_amount']);
            $reference = 'CI-'.Str::upper((string) Str::ulid());
            $snapshot = [
                'reference' => $reference, 'issued_at' => now($shipment->business->timezone)->format('d M Y H:i T'),
                'business' => [
                    'name' => $shipment->business->name, 'country' => $shipment->business->country_code,
                    'code' => $shipment->business->code,
                ],
                'customer' => [
                    'name' => $shipment->customer->name, 'code' => $shipment->customer->customer_code,
                    'country' => $shipment->customer->country_code,
                    'city' => $shipment->customer->city,
                    'email' => $shipment->customer->email,
                ],
                'order' => [
                    'reference' => $order->reference,
                    'confirmation' => $order->buyer_confirmation,
                    'incoterm' => $order->incoterm,
                    'currency' => $order->currency,
                    'discount_percent' => (string) $order->discount_percent,
                    'tax_percent' => (string) $order->tax_percent,
                ],
                'shipment' => [
                    'reference' => $shipment->reference, 'status_at_issue' => $shipment->status,
                    'origin_airport' => $shipment->origin_airport,
                    'destination_airport' => $shipment->destination_airport,
                    'origin_country' => $shipment->origin_country,
                    'destination_country' => $shipment->destination_country,
                    'flight' => $shipment->flight_number, 'air_waybill' => $shipment->air_waybill,
                    'carrier' => $shipment->carrier,
                    'carton_count' => count($cartons),
                    'net_weight_kg' => (string) $net->toScale(3),
                    'gross_weight_kg' => (string) $gross->toScale(3),
                ],
                'lines' => $totals['lines'], 'cartons' => $cartons,
                'totals' => [
                    'subtotal' => $totals['subtotal'], 'discount' => $totals['discount_amount'],
                    'tax' => $totals['tax_amount'], 'freight' => (string) app(SalesPricing::class)->money((string) $data['freight_amount']),
                    'insurance' => (string) app(SalesPricing::class)->money((string) $data['insurance_amount']),
                    'grand_total' => $totals['grand_total'],
                ],
                'notes' => $data['notes'] ?? null,
            ];
            $html = view('sales.invoices.print', ['invoice' => $snapshot])->render();
            $invoice = CommercialInvoice::create([
                'business_id' => $shipment->business_id, 'shipment_id' => $shipment->id,
                'sales_order_id' => $order->id, 'customer_id' => $shipment->customer_id,
                'reference' => $reference, 'currency' => $order->currency,
                'subtotal' => $totals['subtotal'], 'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'freight_amount' => $snapshot['totals']['freight'],
                'insurance_amount' => $snapshot['totals']['insurance'],
                'grand_total' => $totals['grand_total'], 'posting_status' => 'not_posted',
                'data_snapshot' => $snapshot, 'html_snapshot' => $html, 'sha256' => hash('sha256', $html),
                'issued_by' => $userId, 'issued_at' => now(),
            ]);
            InvoiceAccountingHandoff::create([
                'business_id' => $shipment->business_id,
                'commercial_invoice_id' => $invoice->id,
                'currency' => $order->currency,
                'commercial_amount' => $totals['grand_total'],
                'posting_status' => 'awaiting_accounting_policy',
                'prepared_at' => now(),
                'details' => [
                    'shipment' => $shipment->reference,
                    'sales_order' => $order->reference,
                    'customer' => $shipment->customer->customer_code,
                    'incoterm' => $order->incoterm,
                    'recognition_decision' => 'not_determined',
                    'no_journal_entry_created' => true,
                ],
            ]);
            ShipmentEvent::create([
                'business_id' => $shipment->business_id, 'shipment_id' => $shipment->id,
                'actor_id' => $userId, 'action' => 'commercial_invoice_issued',
                'details' => ['invoice' => $reference, 'currency' => $order->currency,
                    'total' => $totals['grand_total'], 'financial_posting' => false],
                'created_at' => now(),
            ]);

            return $invoice;
        }, 3);
    }
}
