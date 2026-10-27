<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CartonRepack;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinancePostingRequest;
use App\Models\GoodsReceipt;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QrIdentity;
use App\Models\ShipmentCarton;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryValuation;
use App\Services\FinanceSetup;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\PackingOperations;
use App\Services\SalesWorkflow;
use App\Services\StockAvailability;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockExceptionsPhaseFourTest extends TestCase
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

    private function captureCount(array $c, string $quantity): StockAdjustment
    {
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('stock.exceptions.count'), ['batch_id' => $c['batch']->id, 'counted_quantity' => $quantity, 'reason' => 'Two staff physically weighed the loose source stock.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return StockAdjustment::query()->where('business_id', $c['business']->id)->latest('id')->firstOrFail();
    }

    public function test_count_shortage_posts_quantity_and_cost_together_and_remaining_cogs_reconciles(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $count = $this->captureCount($c, '8');
        $this->post(route('stock.exceptions.count.review', [$count->id, 'approve']), ['note' => 'Own stock observation cannot be approved.'])->assertForbidden();
        $this->actingAs($c['owner'])->post(route('stock.exceptions.count.review', [$count->id, 'approve']), ['note' => 'Independently checked the count and its historical receipt cost.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('10'));
        $this->postSource($c, 'stock_adjustment', $count->id);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('8'));
        $basis = app(FinanceCostBasis::class)->batchBasis($c['batch']);
        $this->assertTrue($basis['cost']->isEqualTo('800'));
        $this->assertTrue($basis['quantity']->isEqualTo('8'));
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'PACK-COUNT', 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $carton = app(PackingOperations::class)->packCarton($job, ['packing_key' => (string) Str::uuid(), 'net_weight_kg' => '8', 'gross_weight_kg' => '9', 'contents' => [['batch_id' => $c['batch']->id, 'quantity' => '8']]], $c['warehouse']->id);
        app(PackingOperations::class)->complete($job, $c['warehouse']->id);
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUY', 'name' => 'Importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'SHIP-COUNT', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $c['owner']->id]);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'cogs', $shipment->id, $c['accountant']->id);
        $this->assertTrue(BigDecimal::of((string) $relief->base_amount)->isEqualTo('800'));
        $this->postSource($c, 'inventory_relief', $relief->id);
        $this->actingAs($c['owner'])->get(route('stock.exceptions.index'))->assertOk()->assertSee($count->reference);
        $this->get(route('finance.reviews'))->assertOk();
    }

    public function test_changed_stock_requires_recount_and_a_zero_variance_needs_no_journal(): void
    {
        $c = $this->context();
        $count = $this->captureCount($c, '9');
        StockMovement::create(['business_id' => $c['business']->id, 'batch_id' => $c['batch']->id, 'movement_type' => 'packing', 'quantity' => '-1', 'unit' => 'kg', 'source_type' => 'fixture_packing', 'source_id' => 1, 'created_by' => $c['warehouse']->id]);
        $this->actingAs($c['owner'])->post(route('stock.exceptions.count.review', [$count->id, 'approve']), ['note' => 'A stale count must not overwrite later stock movements.'])->assertStatus(422);
        $this->post(route('stock.exceptions.count.review', [$count->id, 'reject']), ['note' => 'Reject the stale observation and ask for a fresh count.'])->assertRedirect();
        $new = $this->captureCount($c, '9');
        $this->actingAs($c['owner'])->post(route('stock.exceptions.count.review', [$new->id, 'approve']), ['note' => 'Current loose stock matches the independently observed count.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('not_required', $new->fresh()->posting_status);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_order_carton_unpack_restores_reservation_disables_old_qr_and_can_pack_a_replacement(): void
    {
        $c = $this->context();
        $customer = Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUY', 'name' => 'Importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        $request = app(SalesWorkflow::class)->createRequest($c['business']->id, ['customer_id' => $customer->id, 'items' => [['product_id' => $c['product']->id, 'quantity' => '4']]], $c['owner']->id);
        $order = app(OrderOperations::class)->approve($c['business'], $request->id, $c['owner']->id, 'Approved actual buyer quantity requirement.');
        $line = $order->lines()->firstOrFail();
        app(OrderSourcing::class)->reserve($line, $c['batch'], ['quantity' => '4', 'reason' => 'Reserve the correct source for this customer.', 'request_key' => (string) Str::uuid()], $c['owner']->id);
        $job = PackingJob::create(['business_id' => $c['business']->id, 'sales_order_line_id' => $line->id, 'reference' => 'PACK-REPACK', 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $packing = ['packing_key' => (string) Str::uuid(), 'net_weight_kg' => '4', 'gross_weight_kg' => '5', 'contents' => [['batch_id' => $c['batch']->id, 'quantity' => '4']]];
        $carton = app(PackingOperations::class)->packCarton($job, $packing, $c['warehouse']->id);
        app(PackingOperations::class)->complete($job, $c['warehouse']->id);
        QrIdentity::query()->where('business_id', $c['business']->id)->where('level', 'carton')->where('record_id', $carton->id)->update(['public_enabled' => true]);
        $this->actingAs($c['warehouse'])->post(route('stock.exceptions.repack'), ['carton_id' => $carton->id, 'reason' => 'Repack after confirming the original packaging damage.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $repack = CartonRepack::firstOrFail();
        $this->actingAs($c['owner'])->post(route('stock.exceptions.repack.review', [$repack->id, 'approve']), ['note' => 'Independent inspection confirms full return to source batches.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('repacked', $carton->fresh()->status);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('10'));
        $this->assertTrue(BigDecimal::of((string) StockReservation::firstOrFail()->consumed_quantity)->isZero());
        $this->assertFalse(QrIdentity::query()->where('level', 'carton')->where('record_id', $carton->id)->firstOrFail()->public_enabled);
        $this->assertSame('0', (string) BigDecimal::of(app(OrderSourcing::class)->summary($line)['packed'])->strippedOfTrailingZeros());
        $replacement = app(PackingOperations::class)->packCarton($job->fresh(), [...$packing, 'packing_key' => (string) Str::uuid()], $c['warehouse']->id);
        $this->assertNotSame($carton->id, $replacement->id);
        $this->assertTrue(BigDecimal::of(app(OrderSourcing::class)->summary($line)['packed'])->isEqualTo('4'));
        $this->post(route('stock.exceptions.repack.review', [$repack->id, 'approve']), ['note' => 'Applying the same unpack twice must be refused.'])->assertStatus(422);
    }

    public function test_location_moves_preserve_quantity_and_foreign_tenant_stock_is_hidden(): void
    {
        $c = $this->context();
        $other = $this->context('OTHER');
        $location = StockLocation::create(['business_id' => $c['business']->id, 'name' => 'Cold room', 'active' => true]);
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('stock.exceptions.move'), ['batch_id' => $c['batch']->id, 'to_location_id' => $location->id, 'reason' => 'Received all loose units into the cold room.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($location->id, $c['batch']->fresh()->stock_location_id);
        $this->assertTrue(app(StockAvailability::class)->available($c['business']->id, $c['batch']->id)->isEqualTo('10'));
        $this->post(route('stock.exceptions.count'), ['batch_id' => $other['batch']->id, 'counted_quantity' => '10', 'reason' => 'Foreign tenant batch cannot be counted here.', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('stock_location_events', 1);
    }
}
