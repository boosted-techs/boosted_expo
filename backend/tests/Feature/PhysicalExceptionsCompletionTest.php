<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\GoodsReceipt;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QrIdentity;
use App\Models\SalesOrder;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\StockExceptionCase;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryReconciliation;
use App\Services\FinanceInventoryValuation;
use App\Services\FinanceProductionCosts;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use App\Services\OperationalToday;
use App\Services\OrderSourcing;
use App\Services\PackingOperations;
use App\Services\ShipmentOperations;
use App\Services\StockAvailability;
use App\Services\StockExceptionCases;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhysicalExceptionsCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'STK'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Stock Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $accountant = User::factory()->create();
        $warehouse = User::factory()->create();
        foreach ([[$owner, 'owner'], [$accountant, 'accountant'], [$warehouse, 'warehouse']] as [$user,$role]) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        }
        app(FinanceSetup::class)->activate($business);
        $product = Product::create(['business_id' => $business->id, 'name' => 'Avocado', 'sku' => 'AVO', 'base_unit' => 'kg', 'active' => true]);
        $batch = StockBatch::create(['business_id' => $business->id, 'product_id' => $product->id, 'batch_code' => 'BATCH-'.$code, 'received_on' => '2026-10-27']);
        StockMovement::create(['business_id' => $business->id, 'batch_id' => $batch->id, 'movement_type' => 'received', 'quantity' => '10', 'unit' => 'kg', 'source_type' => 'fixture_receipt', 'source_id' => 1, 'created_by' => $owner->id]);

        return compact('business', 'owner', 'accountant', 'warehouse', 'product', 'batch');
    }

    private function postSource(array $c, string $type, int $id): void
    {
        $this->actingAs($c['accountant'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.reviews.request', ['type' => $type, 'source' => $id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Verified quantity, source basis and independent business evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('business_id', $c['business']->id)->where('source_type', $type)->where('source_id', $id)->firstOrFail();
        $this->actingAs($c['owner'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independent financial evidence and cost review.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['accountant'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function receiptCost(array $c): void
    {
        $purchase = PurchaseOrder::create(['business_id' => $c['business']->id, 'reference' => 'PO-'.$c['business']->code, 'kind' => 'market', 'product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '100', 'estimated_total' => '1000', 'currency' => 'UGX', 'status' => 'received', 'purchased_on' => '2026-10-27', 'created_by' => $c['owner']->id, 'idempotency_key' => (string) Str::uuid()]);
        GoodsReceipt::create(['business_id' => $c['business']->id, 'reference' => 'RECEIPT-'.$c['business']->code, 'purchase_order_id' => $purchase->id, 'batch_id' => $c['batch']->id, 'received_quantity' => '10', 'accepted_quantity' => '10', 'rejected_quantity' => '0', 'received_on' => '2026-10-27', 'posted_by' => $c['warehouse']->id, 'idempotency_key' => (string) Str::uuid()]);
        $bill = app(FinanceDocuments::class)->supplierBill($c['business'], $purchase, ['vendor_invoice_reference' => 'BILL-001', 'vendor_invoice_date' => '2026-10-27'], $c['accountant']->id);
        $this->postSource($c, 'supplier_bill', $bill->id);
    }

    private function data(array $extra = []): array
    {
        return [...['kind' => 'location_split', 'event_on' => '2026-10-27', 'reason' => 'Verified physical exception with signed independent quantity observations.', 'external_reference' => 'PHYSICAL-001', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function prepare(array $c, array $data): StockExceptionCase
    {
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('stock.cases.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return StockExceptionCase::where('request_key', $data['request_key'])->firstOrFail();
    }

    private function approve(array $c, StockExceptionCase $case): void
    {
        $this->actingAs($c['owner'])->post(route('stock.cases.review', [$case->id, 'approve']), ['note' => 'Independently verified the quantities, custody and original source evidence.'])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function balance(array $c, string $key): string
    {
        return (string) app(FinanceWorkflows::class)->accountBasis($c['business']->id, app(FinanceSetup::class)->account($c['business']->id, $key)->id, '2026-10-27')['base']->toScale(2);
    }

    private function carton(array $c, string $quantity = '8'): Carton
    {
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'PACK-EX-'.Str::ulid(), 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);

        return app(PackingOperations::class)->packCarton($job, ['packing_key' => (string) Str::uuid(), 'net_weight_kg' => $quantity, 'gross_weight_kg' => (string) BigDecimal::of($quantity)->plus('1'), 'contents' => [['batch_id' => $c['batch']->id, 'quantity' => $quantity]]], $c['warehouse']->id);
    }

    public function test_partial_location_split_keeps_cost_quantity_and_source_lineage(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $location = StockLocation::create(['business_id' => $c['business']->id, 'name' => 'Cold room', 'active' => true]);
        $data = $this->data(['batch_id' => $c['batch']->id, 'quantity' => '4', 'to_location_id' => $location->id]);
        $case = $this->prepare($c, $data);
        $this->prepare($c, $data);
        $this->assertDatabaseCount('stock_exception_cases', 1);
        $this->post(route('stock.cases.review', [$case->id, 'approve']), ['note' => 'Own observation must not be approved by its creator.'])->assertForbidden();
        $this->approve($c, $case);
        $case->refresh();
        $split = StockBatch::findOrFail($case->result['batch_id']);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('6'));
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $split->id)->isEqualTo('4'));
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($c['batch'])['cost']->isEqualTo('600'));
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($split)['cost']->isEqualTo('400'));
        $this->assertSame('1000.00', $this->balance($c, 'inventory'));
        $this->assertSame([$c['batch']->id, $split->id], app(StockExceptionCases::class)->lineage($c['business']->id, $c['batch']->id));
        $this->get(route('stock.cases.index'))->assertOk()->assertSee($case->reference);
    }

    public function test_partial_repack_retires_original_sticker_and_returns_only_released_loose_units(): void
    {
        $c = $this->context();
        $carton = $this->carton($c);
        $identity = QrIdentity::where('level', 'carton')->where('record_id', $carton->id)->firstOrFail();
        $case = $this->prepare($c, $this->data(['kind' => 'partial_repack', 'carton_id' => $carton->id, 'release' => [$c['batch']->id => '3'], 'net_weight_kg' => '5', 'gross_weight_kg' => '6']));
        $this->approve($c, $case);
        $carton->refresh();
        $this->assertSame('repacked', $carton->status);
        $this->assertFalse($identity->fresh()->public_enabled);
        $replacement = Carton::findOrFail($case->fresh()->result['retained_carton_id']);
        $this->assertTrue(BigDecimal::of((string) $replacement->contents->first()->quantity)->isEqualTo('5'));
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('5'));
    }

    public function test_customer_return_restores_original_cogs_to_a_new_held_batch_once(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $carton = $this->carton($c);
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'RET', 'name' => 'Returning importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'SHIP-RETURN', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'actual_departure_at' => '2026-10-26 08:00:00', 'created_by' => $c['owner']->id]);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
        $carton->update(['status' => 'shipped']);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'cogs', $shipment->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $relief->id);
        $location = StockLocation::create(['business_id' => $c['business']->id, 'name' => 'Returned goods hold', 'active' => true]);
        $data = $this->data(['kind' => 'customer_return', 'batch_id' => $c['batch']->id, 'carton_id' => $carton->id, 'quantity' => '3', 'to_location_id' => $location->id]);
        $case = $this->prepare($c, $data);
        $this->approve($c, $case);
        $case->refresh();
        $this->assertSame('finance_pending', $case->status);
        $this->postSource($c, 'finance_workflow', $case->workflow_id);
        $returnBatch = StockBatch::findOrFail($case->fresh()->result['returned_batch_id']);
        $this->assertTrue($returnBatch->is_quarantined);
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($returnBatch)['cost']->isEqualTo('300'));
        $this->assertSame('500.00', $this->balance($c, 'inventory'));
        $this->assertSame('500.00', $this->balance($c, 'cogs'));
        $this->actingAs($c['warehouse'])->post(route('stock.cases.store'), [...$data, 'quantity' => '6', 'request_key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_late_cost_after_return_prices_remaining_units_without_repricing_the_first_return(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $carton = $this->carton($c);
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'RET', 'name' => 'Returning importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'SHIP-RETURN', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'actual_departure_at' => '2026-10-26 08:00:00', 'created_by' => $c['owner']->id]);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
        $carton->update(['status' => 'shipped']);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'cogs', $shipment->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $relief->id);
        $location = StockLocation::create(['business_id' => $c['business']->id, 'name' => 'Returned goods hold', 'active' => true]);
        $data = $this->data(['kind' => 'customer_return', 'batch_id' => $c['batch']->id, 'carton_id' => $carton->id, 'quantity' => '3', 'to_location_id' => $location->id]);
        $case = $this->prepare($c, $data);
        $this->approve($c, $case);
        $case->refresh();
        $this->assertSame('finance_pending', $case->status);
        $this->postSource($c, 'finance_workflow', $case->workflow_id);
        $returnBatch = StockBatch::findOrFail($case->fresh()->result['returned_batch_id']);
        $this->assertTrue($returnBatch->is_quarantined);
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($returnBatch)['cost']->isEqualTo('300'));
        $this->assertSame('500.00', $this->balance($c, 'inventory'));
        $this->assertSame('500.00', $this->balance($c, 'cogs'));
        $this->actingAs($c['accountant'])->post(route('finance.money.store'), ['kind' => 'expense', 'currency' => 'UGX', 'amount' => '70', 'event_on' => '2026-10-27', 'due_on' => '2026-11-01', 'category' => 'packing_material', 'description' => 'Late actual source freight invoice', 'external_reference' => 'LATE-COST-001', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $expense = FinanceOperation::latest('id')->firstOrFail();
        $this->postSource($c, 'money_operation', $expense->id);
        $allocation = app(FinanceProductionCosts::class)->prepare($c['business'], 'batch_cost', ['event_on' => '2026-10-27', 'source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '70', 'batch_id' => $c['batch']->id, 'reason' => 'Allocate verified late freight to units not previously returned.', 'external_reference' => 'COST-ALLOC-001', 'request_key' => (string) Str::uuid()], $c['accountant']->id);
        $this->postSource($c, 'finance_workflow', $allocation->id);
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($returnBatch)['cost']->isEqualTo('300'));
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($c['batch'])['unit']->isEqualTo('110'));
        $this->assertSame('520.00', $this->balance($c, 'inventory'));
        $this->assertSame('550.00', $this->balance($c, 'cogs'));
        $second = $this->prepare($c, [...$data, 'quantity' => '2', 'request_key' => (string) Str::uuid()]);
        $this->approve($c, $second);
        $this->postSource($c, 'finance_workflow', $second->fresh()->workflow_id);
        $secondBatch = StockBatch::findOrFail($second->fresh()->result['returned_batch_id']);
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($secondBatch)['cost']->isEqualTo('220'));
        $this->assertSame('740.00', $this->balance($c, 'inventory'));
        $this->assertSame('330.00', $this->balance($c, 'cogs'));
        $reconciliation = app(FinanceInventoryReconciliation::class)->report($c['business'], '2026-10-27');
        $this->assertTrue($reconciliation['matches'], implode(' ', $reconciliation['issues']));
    }

    public function test_replacement_requires_independent_authority_and_tracks_real_quantity_without_revenue(): void
    {
        $c = $this->context('REP');
        $this->receiptCost($c);
        $carton = $this->carton($c, '7');
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'REP', 'name' => 'Affected importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'SHIP-REPLACEMENT', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'actual_departure_at' => '2026-10-27 08:00:00', 'created_by' => $c['owner']->id]);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
        $carton->update(['status' => 'shipped']);
        $case = $this->prepare($c, $this->data(['kind' => 'quality_claim', 'batch_id' => $c['batch']->id]));
        $data = ['shipment_id' => $shipment->id, 'quantity' => '3', 'required_on' => '2026-10-29', 'external_reference' => 'AGREED-REP-001', 'reason' => 'Independent authorisation of three damaged units replacement.', 'request_key' => (string) Str::uuid()];
        $this->post(route('stock.cases.replacement', $case->id), $data)->assertForbidden();
        $this->actingAs($c['owner'])->post(route('stock.cases.replacement', $case->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $order = SalesOrder::query()->where('replacement_case_id', $case->id)->firstOrFail();
        $this->assertSame('replacement', $order->order_type);
        $this->assertNull($order->grand_total);
        $this->assertSame($shipment->id, $order->replacement_shipment_id);
        $this->post(route('stock.cases.replacement', $case->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_orders', 1);
        $this->post(route('stock.cases.replacement', $case->id), [...$data, 'quantity' => '6', 'request_key' => (string) Str::uuid()])->assertStatus(422);
        $this->post(route('stock.cases.progress', $case->id), ['action' => 'close', 'note' => 'This cannot close before the replacement goods are delivered.'])->assertStatus(422);
        $this->get(route('sales-orders.show', $order->id))->assertOk()->assertSee('Replacement')->assertSee('no sales charge');
        $this->get(route('stock.cases.index'))->assertOk()->assertSee($order->reference);
        $originalRelief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'cogs', $shipment->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $originalRelief->id);
        $line = $order->lines()->firstOrFail();
        app(OrderSourcing::class)->reserve($line, $c['batch'], ['quantity' => '3', 'request_key' => (string) Str::uuid(), 'reason' => 'Reserve the separately authorised no-charge replacement goods.'], $c['owner']->id);
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'PACK-REPLACEMENT', 'product_id' => $c['product']->id, 'sales_order_line_id' => $line->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $newCarton = app(PackingOperations::class)->packCarton($job, ['packing_key' => (string) Str::uuid(), 'net_weight_kg' => '3', 'gross_weight_kg' => '4', 'contents' => [['batch_id' => $c['batch']->id, 'quantity' => '3']]], $c['warehouse']->id);
        $replacementShipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'sales_order_id' => $order->id, 'reference' => 'REPLACEMENT-DELIVERY', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'actual_departure_at' => '2026-10-27 12:00:00', 'created_by' => $c['owner']->id]);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $replacementShipment->id, 'carton_id' => $newCarton->id, 'allocated_by' => $c['owner']->id]);
        $newCarton->update(['status' => 'shipped']);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'cogs', $replacementShipment->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $relief->id);
        $this->assertSame('300.00', $this->balance($c, 'quality_claim_loss'));
        $this->assertSame('700.00', $this->balance($c, 'cogs'));
        $this->assertSame('0.00', $this->balance($c, 'inventory'));
        $this->assertSame('0.00', $this->balance($c, 'export_sales'));
        app(ShipmentOperations::class)->arrive($replacementShipment, '2026-10-27 14:00:00', $c['owner']->id);
        $this->actingAs($c['owner'])->post(route('stock.cases.progress', $case->id), ['action' => 'close', 'note' => 'Actual replacement goods delivered and the loss cost independently posted.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $case->fresh()->status);
        $csv = $this->get(route('stock.cases.index', ['from' => '2026-10-27', 'to' => '2026-10-27', 'status' => 'closed', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString($case->reference, $csv);
        $this->assertStringNotContainsString($case->reference, $this->get(route('stock.cases.index', ['status' => 'open', 'export' => 'csv']))->assertOk()->streamedContent());

    }

    public function test_supplier_return_removes_actual_quantity_and_keeps_unagreed_credit_visible(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $case = $this->prepare($c, $this->data(['kind' => 'supplier_return', 'batch_id' => $c['batch']->id, 'quantity' => '2']));
        $this->approve($c, $case);
        $this->postSource($c, 'finance_workflow', $case->fresh()->workflow_id);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('8'));
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($c['batch'])['cost']->isEqualTo('800'));
        $this->assertSame('800.00', $this->balance($c, 'inventory'));
        $this->assertSame('200.00', $this->balance($c, 'supplier_return_pending'));
        $this->assertSame('-1000.00', $this->balance($c, 'payable'));
    }

    public function test_recall_holds_descendant_stock_and_requires_affected_shipments_to_be_accounted_for(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $location = StockLocation::create(['business_id' => $c['business']->id, 'name' => 'Other room', 'active' => true]);
        $split = $this->prepare($c, $this->data(['batch_id' => $c['batch']->id, 'quantity' => '4', 'to_location_id' => $location->id]));
        $this->approve($c, $split);
        $recall = $this->prepare($c, $this->data(['kind' => 'recall', 'batch_id' => $c['batch']->id]));
        $this->assertTrue($c['batch']->fresh()->is_quarantined);
        $child = StockBatch::findOrFail($split->fresh()->result['batch_id']);
        $this->assertTrue($child->is_quarantined);
        $this->actingAs($c['owner'])->post(route('stock.quality.change', [$child->id, 'release']), ['reason' => 'Release is blocked while the original source recall is open.'])->assertStatus(422);
        $this->post(route('stock.cases.progress', $recall->id), ['action' => 'close', 'note' => 'All held loose stock inspected and recall disposition independently documented.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $recall->fresh()->status);
        $this->post(route('stock.quality.change', [$child->id, 'release']), ['reason' => 'Recall was independently resolved and this stock was inspected.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.cases.progress', $recall->id), ['action' => 'reopen', 'due_on' => '2026-10-28', 'note' => 'New customer complaint requires a renewed inspection of all affected stock.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('investigating', $recall->fresh()->status);
        $this->assertSame('2026-10-28', $recall->fresh()->due_on->toDateString());
        $work = app(OperationalToday::class)->forMember(BusinessMembership::where('business_id', $c['business']->id)->where('user_id', $c['owner']->id)->firstOrFail());
        $this->assertTrue($work['stockCases']->contains('id', $recall->id));
        $this->assertTrue($child->fresh()->is_quarantined);
        Storage::fake('local');
        $this->post(route('stock.cases.evidence.store', $recall->id), ['file' => UploadedFile::fake()->createWithContent('complaint.pdf', "%PDF-1.4\nOriginal claim evidence"), 'note' => 'Original customer complaint retained for independent investigation.'])->assertRedirect()->assertSessionHasNoErrors();
        $file = DB::table('stock_case_evidence')->first();
        $this->get(route('stock.cases.evidence.download', $file->id))->assertOk();
        $other = $this->context('OTHER');
        $this->actingAs($other['owner'])->withSession(['active_business_id' => $other['business']->id])->get(route('stock.cases.evidence.download', $file->id))->assertNotFound();
        Storage::disk('local')->put($file->path, 'Changed original evidence');
        $this->actingAs($c['owner'])->withSession(['active_business_id' => $c['business']->id])->get(route('stock.cases.evidence.download', $file->id))->assertStatus(422);
        $this->post(route('stock.cases.progress', $recall->id), ['action' => 'close', 'note' => 'Cannot close an investigation with changed original evidence.'])->assertStatus(422);

    }
}
