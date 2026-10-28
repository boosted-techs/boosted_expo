<?php

namespace Database\Seeders;

use App\Mail\CustomerOrderingMail;
use App\Models\BusinessDestination;
use App\Models\BusinessOperatingPolicy;
use App\Models\ExportDocumentTemplate;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelTemplate;
use App\Models\MarketTrip;
use App\Models\PackingJob;
use App\Models\PartnerFollowup;
use App\Models\PurchaseOrder;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Models\TransportPartner;
use App\Services\BuyerOrderAccess;
use App\Services\CommercialInvoiceIssuance;
use App\Services\CustomerOrdering;
use App\Services\ExportDocuments;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryValuation;
use App\Services\GoodsReceiving;
use App\Services\LabelPrintQueue;
use App\Services\LabelWorkflow;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\PackingOperations;
use App\Services\QuotationAccessService;
use App\Services\SalesFulfillment;
use App\Services\SalesWorkflow;
use App\Services\ShipmentDispatchApproval;
use App\Services\ShipmentOperations;
use App\Services\SupplierQuoting;
use App\Services\TransportOperations;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ExporterDemoOperationsSeeder extends Seeder
{
    public function run(ExporterDemoContext $d): void
    {
        $b = $d->business;
        $sales = $d->id('sales');
        $buyer = $d->id('buyer');
        $warehouse = $d->id('warehouse');
        $packer = $d->id('packing');
        $logistics = $d->id('logistics');
        app(CustomerOrdering::class)->invite($b, $d->get('customer'), [$d->get('AVO')->id, $d->get('CHILLI')->id], $sales);
        $token = Mail::sent(CustomerOrderingMail::class)->last()->token;
        $d->links['customer_ordering'] = route('customer-ordering.show', $token);
        $destination = BusinessDestination::create(['business_id' => $b->id, 'name' => 'Dubai', 'name_key' => 'dubai', 'country_code' => 'AE']);
        $request = app(CustomerOrdering::class)->submit($token, ['items' => [['product_id' => $d->get('AVO')->id, 'quantity' => '100']], 'required_on' => $d->date(-2), 'notes' => $d->note('Buyer requests quantities first. Prices are agreed separately.'), 'buyer_reference' => 'DEMO-DUBAI-WEEKLY', 'destination' => 'Dubai', 'destination_id' => $destination->id, 'submission_key' => $d->key()]);
        $workflow = app(SalesWorkflow::class);
        $quote = $workflow->draft($b->id, $request->id, $sales);
        $quote = $workflow->updateDraft($quote, ['currency' => 'USD', 'pricing_exchange_rate_id' => $d->get('usd_rate')->id, 'items' => [['product_id' => $d->get('AVO')->id, 'quantity' => '100', 'unit_price' => '3']], 'payment_terms_days' => 14, 'incoterm' => 'FCA', 'valid_until' => $d->date(-14), 'notes' => $d->note('USD customer price, UGX internal conversion is maintained separately.')]);
        $workflow->issue($quote, $sales);
        $quoteToken = app(QuotationAccessService::class)->create($quote, $d->get('customer')->email, $sales);
        $d->links['quotation'] = route('buyer-quotation.show', $quoteToken);
        $order = $workflow->confirm($quote, $d->note('Illustrative buyer approval of USD quotation.'), $sales);
        $d->put('order', $order);
        $line = $order->lines()->firstOrFail();
        $d->put('order_line', $line);
        app(BuyerOrderAccess::class)->invite($order, $sales);
        foreach (['buyer' => 'Confirm Mukono delivery', 'packing' => 'Sort and pack export-grade avocado', 'logistics' => 'Book airport collection'] as $role => $title) {
            app(OrderOperations::class)->task($order, ['staff_person_id' => $d->get('staff_'.$role)->id, 'title' => 'DEMO · '.$title, 'kind' => $role, 'status' => 'open', 'due_at' => now('Africa/Kampala')->addHours(4)->format('Y-m-d H:i:s'), 'note' => $d->note('Assigned responsibility visible to the owner.'), 'request_key' => $d->key()], $d->id('owner'));
        }
        $rfq = SupplyRequest::create(['business_id' => $b->id, 'reference' => 'DEMO-RFQ-AVO', 'product_id' => $d->get('AVO')->id, 'quantity' => '60', 'currency' => 'UGX', 'needed_on' => $d->date(), 'response_deadline' => now()->addDay(), 'status' => 'sent', 'notes' => $d->note('Compare farmer and market offers before splitting supply.'), 'created_by' => $buyer]);
        foreach (['supplier' => 2100, 'vendor' => 2300] as $key => $price) {
            $token = Str::random(64);
            SupplierRequestLink::create(['business_id' => $b->id, 'supply_request_id' => $rfq->id, 'supplier_id' => $d->get($key)->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
            $q = app(SupplierQuoting::class)->record($rfq, $d->get($key), ['quantity' => '60', 'unit_price' => (string) $price, 'delivery_on' => $d->date(), 'notes' => $d->note('Illustrative supplier offer.')], 'staff');
            if ($key === 'supplier') {
                $d->put('supplier_quote', $q);
            }
        }
        $d->get('supplier_quote')->update(['status' => 'selected']);
        $rfq->update(['status' => 'awarded']);
        $trip = MarketTrip::create(['business_id' => $b->id, 'reference' => 'DEMO-MARKET-TODAY', 'staff_person_id' => $d->get('staff_buyer')->id, 'location' => 'Nakasero Market, Kampala', 'due_at' => now()->addHours(2), 'budget' => '150000', 'currency' => 'UGX', 'status' => 'open', 'notes' => $d->note('Staff buying produce directly from the market.'), 'request_key' => $d->key(), 'created_by' => $buyer]);
        $d->put('market_trip', $trip);
        $sourcing = app(OrderSourcing::class);
        $market = $sourcing->supply($line, ['kind' => 'market', 'quantity' => '40', 'market_trip_id' => $trip->id, 'reason' => $d->note('Remaining demand assigned to a staff market trip.'), 'request_key' => $d->key()], $buyer);
        foreach (['supplier' => ['60', '58', '2', '2100'], 'market' => ['40', '40', '0', '2300']] as $key => [$qty,$accepted,$rejected,$price]) {
            $po = PurchaseOrder::create(['business_id' => $b->id, 'reference' => 'DEMO-PO-'.strtoupper($key), 'kind' => $key, 'supplier_id' => $key === 'supplier' ? $d->get('supplier')->id : null, 'supplier_quote_id' => $key === 'supplier' ? $d->get('supplier_quote')->id : null, 'market_trip_id' => $key === 'market' ? $trip->id : null, 'product_id' => $d->get('AVO')->id, 'vendor_name' => $key === 'market' ? 'DEMO Nakasero vendor' : null, 'purchased_on' => $d->date(), 'quantity' => $qty, 'unit_price' => $price, 'estimated_total' => (string) ((int) $qty * (int) $price), 'currency' => 'UGX', 'status' => 'ordered', 'idempotency_key' => $d->key(), 'notes' => $d->note('Order demand split between farmer and staff market buying.'), 'created_by' => $buyer]);
            $d->put('purchase_'.$key, $po);
            $sourcing->supply($line, ['kind' => 'purchase', 'quantity' => $qty, 'purchase_order_id' => $po->id, 'origin_supply_id' => $key === 'market' ? $market->id : null, 'reason' => $d->note('Committed purchase against this customer order.'), 'request_key' => $d->key()], $buyer);
            $receipt = app(GoodsReceiving::class)->post($po, ['accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'received_on' => $d->date(), 'gross_weight_kg' => (string) ((int) $qty + 2), 'tare_weight_kg' => '2', 'farmer_code' => 'DEMO-'.strtoupper($key), 'quality_notes' => $d->note('Illustrative sorting acceptance and rejects.'), 'idempotency_key' => $d->key()], $warehouse);
            $d->put('receipt_'.$key, $receipt);
            $d->put('batch_'.$key, $receipt->batch);
            $bill = app(FinanceDocuments::class)->supplierBill($b, $po->fresh(), ['vendor_invoice_reference' => 'DEMO-SUPPLIER-INVOICE-'.strtoupper($key), 'vendor_invoice_date' => $d->date(), 'due_on' => $d->date(-7), 'notes' => $d->note('Only accepted produce is billed.')], $d->id('accountant'));
            $d->post('supplier_bill', $bill);
            $d->put('bill_'.$key, $bill);
        }
        PartnerFollowup::create(['business_id' => $b->id, 'supplier_id' => $d->get('supplier')->id, 'title' => 'DEMO rejected produce follow-up', 'staff_person_id' => $d->get('staff_buyer')->id, 'due_at' => now()->addDay(), 'status' => 'open', 'note' => $d->note('Discuss two rejected kilograms and future quality.'), 'created_by' => $buyer]);
        $job = PackingJob::create(['business_id' => $b->id, 'sales_order_line_id' => $line->id, 'reference' => 'DEMO-PACK-AVO', 'product_id' => $d->get('AVO')->id, 'status' => 'open', 'planned_on' => $d->date(), 'target_cartons' => 4, 'packing_type' => '20 kg export carton', 'notes' => $d->note('Order-linked packing consumes reserved batches.'), 'created_by' => $packer]);
        $d->put('packing_job', $job);
        $cartons = [];
        for ($i = 0; $i < 4; $i++) {
            $batch = $i < 2 ? $d->get('batch_supplier') : $d->get('batch_market');
            $cartons[] = app(PackingOperations::class)->packCarton($job, ['packing_key' => $d->key(), 'contents' => [['batch_id' => $batch->id, 'quantity' => '20']], 'net_weight_kg' => '20', 'gross_weight_kg' => '21', 'grade' => 'A', 'packaging_type' => 'Export carton'], $packer);
        }
        $waste = app(PackingOperations::class)->recordWaste($job, ['batch_id' => $d->get('batch_supplier')->id, 'quantity' => '2', 'reason' => 'sorting_reject', 'idempotency_key' => $d->key()], $packer);
        $d->put('waste', $waste);
        app(PackingOperations::class)->complete($job, $packer);
        $d->records['carton_one'] = $cartons[0];
        $d->records['carton_spare'] = $cartons[3];
        $shipment = app(ShipmentOperations::class)->create($b->id, ['creation_key' => $d->key(), 'customer_id' => $d->get('customer')->id, 'origin_country' => 'UG', 'destination_country' => 'AE', 'origin_airport' => 'Entebbe (EBB)', 'destination_airport' => 'Dubai (DXB)', 'carrier' => 'DEMO training carrier', 'flight_number' => 'DEMO-730', 'air_waybill' => 'DEMO-NOT-REAL-AWB', 'scheduled_departure_at' => now('Africa/Kampala')->subHours(3)->format('Y-m-d H:i:s'), 'estimated_arrival_at' => now('Africa/Kampala')->addHours(3)->format('Y-m-d H:i:s'), 'incoterm' => 'FCA', 'notes' => $d->note('Training shipment; not an actual flight.')], $logistics);
        app(SalesFulfillment::class)->linkShipment($shipment, $order, $logistics);
        $ids = array_map(fn ($c) => $c->id, array_slice($cartons, 0, 3));
        app(ShipmentOperations::class)->allocate($shipment, $ids, $logistics);
        $d->put('shipment', $shipment);
        $profile = LabelMediaProfile::create(['business_id' => $b->id, 'name' => 'DEMO Zebra GK888t · 100 × 50 mm', 'printer_model' => 'Zebra GK888t', 'protocol' => 'zpl', 'dpi' => 203, 'width_mm' => 100, 'height_mm' => 50, 'margin_mm' => 2, 'offset_x_mm' => 0, 'offset_y_mm' => 0, 'media_type' => 'gap', 'orientation' => 'portrait', 'test_evidence' => $d->note('Awaiting physical calibration on Windows, Mac or Android. No printer has been verified.'), 'created_by' => $d->id('owner')]);
        $template = LabelTemplate::create(['business_id' => $b->id, 'name' => 'DEMO export carton 100 × 50 mm', 'width_mm' => 100, 'height_mm' => 50, 'dpi' => 203, 'qr_level' => 'none', 'qr_visibility' => 'internal', 'include_barcode' => true, 'visible_fields' => ['company', 'product', 'carton', 'net'], 'required_fields' => ['carton'], 'version' => 1, 'active' => true, 'created_by' => $d->id('owner'), 'media_profile_id' => $profile->id, 'layout' => ['mode' => 'bitmap'], 'is_default' => true]);
        $d->put('label_template', $template);
        app(LabelWorkflow::class)->generate($b, $template, $ids, 1, $d->key(), $packer);
        foreach (LabelGeneration::where('business_id', $b->id)->get() as $label) {
            app(LabelWorkflow::class)->verify($b, $label->id, $label->scan_token, $d->note('Software-only simulation of a revision scan; no physical sticker or printer acceptance.'), $packer);
        }
        $agent = app(LabelPrintQueue::class)->register($b, 'DEMO packing workstation · offline', $d->id('owner'), $profile->id);
        $d->put('print_agent', $agent[0]);
        app(LabelPrintQueue::class)->enqueue($b, $agent[0]->id, LabelGeneration::where('business_id', $b->id)->firstOrFail()->id, $d->key(), $packer);
        $partner = TransportPartner::create(['business_id' => $b->id, 'partner_code' => 'DEMO-TRUCK', 'name' => 'DEMO · Kampala Airport Cold Chain', 'kind' => 'road', 'phone' => '+256000000000', 'active' => true, 'notes' => $d->note('Fictional transporter with named custody evidence.')]);
        $d->put('transporter', $partner);
        $transport = app(TransportOperations::class);
        $leg = $transport->plan($shipment, ['transport_partner_id' => $partner->id, 'staff_person_id' => $d->get('staff_logistics')->id, 'sequence' => 1, 'mode' => 'road', 'origin' => 'Kampala packhouse', 'destination' => 'Entebbe cargo terminal', 'vehicle_reference' => 'DEMO-TRUCK-01', 'due_at' => now('Africa/Kampala')->subHours(3)->format('Y-m-d H:i:s'), 'carton_ids' => $ids, 'request_key' => $d->key(), 'notes' => $d->note('Illustrative full-manifest airport collection.')], $logistics);
        foreach (['collected' => 5, 'handed_over' => 4] as $action => $hours) {
            $transport->handover($leg, ['action' => $action, 'occurred_at' => now('Africa/Kampala')->subHours($hours)->format('Y-m-d H:i:s'), 'evidence_reference' => 'DEMO-'.$action, 'from_person' => 'DEMO packhouse supervisor', 'to_person' => 'DEMO cargo agent', 'notes' => $d->note('Simulated custody receipt.'), 'request_key' => $d->key()], $logistics);
        }
        app(ShipmentOperations::class)->ready($shipment->fresh(), $logistics);
        BusinessOperatingPolicy::where('business_id', $b->id)->update(['dispatch_approval_required' => true]);
        app(ShipmentDispatchApproval::class)->request($shipment->fresh(), $logistics);
        app(ShipmentDispatchApproval::class)->review($shipment->fresh(), $d->id('owner'), true, $d->note('Independent demonstration dispatch review.'));
        $document = ExportDocumentTemplate::create(['business_id' => $b->id, 'name' => 'DEMO packing list', 'type' => 'packing_list', 'paper_size' => 'A4', 'orientation' => 'landscape', 'visible_columns' => ['carton', 'product', 'farmer', 'batches', 'net', 'tare', 'gross', 'boxes'], 'header_fields' => ['customer', 'carrier', 'flight', 'awb', 'route', 'schedule', 'incoterm'], 'version' => 1, 'active' => true, 'created_by' => $logistics]);
        app(ExportDocuments::class)->issue($shipment->fresh(), $document, $logistics);
        $invoice = app(CommercialInvoiceIssuance::class)->issue($shipment->fresh(), ['freight_amount' => '0', 'insurance_amount' => '0', 'notes' => $d->note('60 kg shipment billed at the agreed USD price.')], $sales);
        $d->put('invoice', $invoice);
        app(ShipmentOperations::class)->dispatch($shipment->fresh(), now()->subHours(3)->toDateTimeString(), $logistics);
        $d->post('commercial_invoice', $invoice, $d->date(), $d->get('usd_rate')->id);
        foreach (['cogs' => $shipment->id, 'waste' => $waste->id] as $kind => $id) {
            $relief = app(FinanceInventoryValuation::class)->prepare($b, $kind, $id, $d->id('accountant'));
            $d->post('inventory_relief', $relief);
        }
    }
}
