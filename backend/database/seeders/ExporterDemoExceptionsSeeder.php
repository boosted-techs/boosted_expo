<?php

namespace Database\Seeders;

use App\Models\CommercialInvoice;
use App\Models\LabelGeneration;
use App\Models\PackingJob;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Services\CommercialInvoiceIssuance;
use App\Services\FinanceBalanceAdjustments;
use App\Services\FinanceCreditNotes;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryValuation;
use App\Services\FinanceOperations;
use App\Services\FinancePeriodControls;
use App\Services\FinanceTradeControls;
use App\Services\FinanceTradeCredits;
use App\Services\FinanceTransactionCorrections;
use App\Services\GoodsReceiving;
use App\Services\LabelWorkflow;
use App\Services\OrderAmendments;
use App\Services\PackingOperations;
use App\Services\PurchaseAmendments;
use App\Services\SalesFulfillment;
use App\Services\ShipmentDispatchApproval;
use App\Services\ShipmentOperations;
use App\Services\StockExceptionCases;
use App\Services\StockExceptions;
use App\Services\StockQualityControls;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;

class ExporterDemoExceptionsSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $owner = $d->id('owner');
        $warehouse = $d->id('warehouse');
        $a = $d->id('accountant');
        $stock = app(StockExceptions::class);
        $cases = app(StockExceptionCases::class);
        $stock->move($b, ['batch_id' => $d->get('opening_batch')->id, 'to_location_id' => $d->get('cold_location')->id, 'reason' => $d->note('Move remaining loose opening stock to cold storage.'), 'request_key' => $d->key()], $warehouse);
        $split = $cases->prepare($b, 'location_split', $d->input(['batch_id' => $d->get('opening_batch')->id, 'quantity' => '10', 'to_location_id' => $d->get('location')->id]), $warehouse);
        $cases->review($b, $split, true, $d->note('Independent review of location split quantity.'), $owner);
        $d->put('split_case', $split);
        $count = $stock->count($b, ['batch_id' => $d->get('opening_batch')->id, 'counted_quantity' => '189', 'reason' => $d->note('Illustrative one-kilogram shortage from independent physical count.'), 'request_key' => $d->key()], $warehouse);
        $stock->review($count, true, $d->note('Count independently checked; UGX value reviewed separately.'), $owner);
        $d->post('stock_adjustment', $count);
        $repack = $stock->requestRepack($b, ['carton_id' => $d->get('carton_spare')->id, 'reason' => $d->note('Torn outer packaging awaiting independent repack review.'), 'request_key' => $d->key()], $d->id('packing'));
        $d->put('repack', $repack);
        app(StockQualityControls::class)->change($b, $d->get('opening_batch')->id, true, $d->note('Quality hold awaiting further inspection; blocks new use.'), $warehouse);
        $quality = $cases->prepare($b, 'quality_claim', $d->input(['batch_id' => $d->get('batch_market')->id, 'staff_person_id' => $d->get('staff_buyer')->id, 'due_on' => $d->date(-2)]), $warehouse);
        $d->put('quality_case', $quality);
        $flow = app(FinanceTradeCredits::class)->prepare($b, $d->input(['document_type' => 'supplier', 'document_id' => $d->get('bill_market')->id, 'case_id' => $quality->id, 'amount' => '5000', 'tax_amount' => '0']), $a);
        $d->post('finance_workflow', $flow);
        $pending = SalesOrder::where('business_id', $b->id)->where('customer_id', $d->get('customer_ug')->id)->where('pricing_status', 'unpriced')->firstOrFail();
        $pendingLine = $pending->lines()->firstOrFail();
        app(OrderAmendments::class)->propose($b, $pending, $d->input(['lines' => [['id' => $pendingLine->id, 'quantity' => '25']], 'buyer_mode' => 'external', 'buyer_name' => 'DEMO Regional Buyer', 'buyer_reference' => 'DEMO-AMENDMENT-PHONE', 'buyer_evidence' => $d->note('Illustrative buyer request to add 5 kg.'), 'required_on' => $d->date(-3)]), $d->id('sales'));
        $purchase = PurchaseOrder::where('business_id', $b->id)->where('reference', 'DEMO-PENDING-PINE-BUY')->firstOrFail();
        app(PurchaseAmendments::class)->propose($b, $purchase, $d->input(['quantity' => '25', 'unit_price' => '2500', 'expected_on' => $d->date(-1), 'agreement_reference' => 'DEMO-SUPPLIER-AMENDMENT']), $d->id('buyer'));
        app(FinanceTradeControls::class)->closeMarket($b, $d->get('market_trip'), $d->note('All market goods received and billed; outstanding supplier debt retained explicitly.'), $owner);
        app(FinanceCreditNotes::class)->prepare($b, $this->correctionInvoice($d), $d->note('Full invoice correction example awaiting financial review; original remains posted.'), $a);
        $po = PurchaseOrder::create(['business_id' => $b->id, 'reference' => 'DEMO-PO-CHILLI', 'kind' => 'supplier', 'supplier_id' => $d->get('supplier')->id, 'product_id' => $d->get('CHILLI')->id, 'purchased_on' => $d->date(), 'quantity' => '10', 'unit_price' => '5000', 'estimated_total' => '50000', 'currency' => 'UGX', 'status' => 'ordered', 'idempotency_key' => $d->key(), 'notes' => $d->note('Unpacked produce used to demonstrate a supplier price concession.'), 'created_by' => $d->id('buyer')]);
        app(GoodsReceiving::class)->post($po, ['accepted_quantity' => '10', 'rejected_quantity' => '0', 'received_on' => $d->date(), 'idempotency_key' => $d->key()], $warehouse);
        $bill = app(FinanceDocuments::class)->supplierBill($b, $po->fresh(), ['vendor_invoice_reference' => 'DEMO-CHILLI-BILL', 'vendor_invoice_date' => $d->date(), 'due_on' => $d->date(-4)], $a);
        $d->post('supplier_bill', $bill);
        $adjust = app(FinanceBalanceAdjustments::class)->prepare($b, 'supplier', $bill->id, '1000', $d->note('Agreed concession on unconsumed produce.'), $a);
        $d->post('balance_adjustment', $adjust);
        app(FinanceTransactionCorrections::class)->prepare($b, 'payment', $d->get('supplier_payment')->id, $d->note('Cash payment return request awaiting independent review.'), $d->note('Illustrative return voucher and custodian statement.'), $a);
        $periods = app(FinancePeriodControls::class);
        $from = now('Africa/Kampala')->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
        $to = now('Africa/Kampala')->subMonthsNoOverflow(2)->endOfMonth()->toDateString();
        $period = $periods->create($b, $from, $to, $a);
        $periods->submit($period, $a, $d->note('Empty historical demo period before opening balances.'));
        $periods->review($period->fresh(), true, $owner, $d->note('Independent empty-period close review.'));
        $periods->requestReopen($period->fresh(), $a, ['reason' => $d->note('Example reopening request awaiting review.'), 'materiality_assessment' => $d->note('No balances in this historical training period.'), 'external_evidence' => $d->note('Training close checklist only.')]);
    }

    private function correctionInvoice(ExporterDemoContext $d): CommercialInvoice
    {
        $b = $d->business;
        $packer = $d->id('packing');
        $logistics = $d->id('logistics');
        $a = $d->id('accountant');
        $payment = app(FinanceDocuments::class)->payment($b, ['direction' => 'in', 'commercial_invoice_id' => $d->get('invoice')->id, 'amount' => '50', 'payment_on' => $d->date(), 'cash_account_key' => 'bank', 'bank_profile_id' => $d->get('usd_bank')->id, 'external_reference' => 'DEMO-BUYER-RECEIPT', 'notes' => $d->note('Buyer partial receipt; posted USD bank and UGX debt carrying value.'), 'idempotency_key' => $d->key()], $a);
        $d->post('payment', $payment, $d->date(), $d->get('closing_rate')->id);
        $d->put('buyer_receipt', $payment);
        $apply = app(FinanceOperations::class)->prepare($b, ['kind' => 'customer_apply', 'parent_id' => $d->get('deposit')->id, 'commercial_invoice_id' => $d->get('invoice')->id, 'currency' => 'USD', 'amount' => '50', 'event_on' => $d->date(), 'description' => $d->note('Apply part of the earlier buyer deposit; do not receive it twice.'), 'external_reference' => 'DEMO-DEPOSIT-ALLOCATION', 'idempotency_key' => $d->key()], $a);
        $d->post('money_operation', $apply);
        $d->put('deposit_application', $apply);
        $job = PackingJob::create(['business_id' => $b->id, 'sales_order_line_id' => $d->get('order_line')->id, 'reference' => 'DEMO-PACK-CORRECTION', 'product_id' => $d->get('AVO')->id, 'status' => 'open', 'planned_on' => $d->date(), 'target_cartons' => 1, 'notes' => $d->note('Separate shipment retains a correction example while the main invoice can receive payments.'), 'created_by' => $packer]);
        $carton = app(PackingOperations::class)->packCarton($job, ['packing_key' => $d->key(), 'contents' => [['batch_id' => $d->get('batch_supplier')->id, 'quantity' => '10']], 'net_weight_kg' => '10', 'gross_weight_kg' => '11', 'grade' => 'A', 'packaging_type' => 'Export carton'], $packer);
        app(PackingOperations::class)->complete($job, $packer);
        $ops = app(ShipmentOperations::class);
        $shipment = $ops->create($b->id, ['creation_key' => $d->key(), 'customer_id' => $d->get('customer')->id, 'origin_country' => 'UG', 'destination_country' => 'AE', 'origin_airport' => 'Entebbe (EBB)', 'destination_airport' => 'Dubai (DXB)', 'carrier' => 'DEMO training carrier', 'flight_number' => 'DEMO-731', 'air_waybill' => 'DEMO-CORRECTION-AWB', 'scheduled_departure_at' => now('Africa/Kampala')->subHour()->format('Y-m-d H:i:s'), 'notes' => $d->note('Separate example shipment with a pending invoice correction.')], $logistics);
        app(SalesFulfillment::class)->linkShipment($shipment, $d->get('order'), $logistics);
        $ops->allocate($shipment, [$carton->id], $logistics);
        app(LabelWorkflow::class)->generate($b, $d->get('label_template'), [$carton->id], 1, $d->key(), $packer);
        $label = LabelGeneration::where('carton_id', $carton->id)->firstOrFail();
        app(LabelWorkflow::class)->verify($b, $label->id, $label->scan_token, $d->note('Software-only label scan simulation; physical verification is still pending.'), $packer);
        $ops->ready($shipment, $logistics);
        $approval = app(ShipmentDispatchApproval::class);
        $approval->request($shipment->fresh(), $logistics);
        $approval->review($shipment->fresh(), $d->id('owner'), true, $d->note('Independent demo dispatch review.'));
        $invoice = app(CommercialInvoiceIssuance::class)->issue($shipment->fresh(), ['freight_amount' => '0', 'insurance_amount' => '0', 'notes' => $d->note('Separate 10 kg invoice used for a pending full-credit example.')], $d->id('sales'));
        $ops->dispatch($shipment->fresh(), now()->subHour()->toDateTimeString(), $logistics);
        $d->post('commercial_invoice', $invoice, $d->date(), $d->get('closing_rate')->id);
        $relief = app(FinanceInventoryValuation::class)->prepare($b,'cogs',$shipment->id,$a);
        $d->post('inventory_relief',$relief);

        return $invoice->fresh();
    }
}
