<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\GoodsReceipt;
use App\Models\MaterialLot;
use App\Models\PackingJob;
use App\Models\PackingWasteEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryValuation;
use App\Services\FinanceProductionCosts;
use App\Services\FinanceSetup;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionCostCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'STK'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Cost Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
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
        return [...['kind' => 'material_receipt', 'event_on' => '2026-10-27', 'reason' => 'Verified direct material receipt and documented cost classification.', 'external_reference' => 'MATERIAL-001', 'request_key' => (string) Str::uuid()], ...$extra];
    }

    private function expense(array $c, string $amount = '1000'): FinanceOperation
    {
        $this->actingAs($c['accountant'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.money.store'), ['kind' => 'expense', 'currency' => 'UGX', 'amount' => $amount, 'event_on' => '2026-10-27', 'due_on' => '2026-11-01', 'category' => 'packing_material', 'description' => 'Actual packaging purchase invoice', 'external_reference' => 'MATERIAL-PURCHASE', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $source = FinanceOperation::latest('id')->firstOrFail();
        $this->postSource($c, 'money_operation', $source->id);

        return $source;
    }

    private function prepare(array $c, array $data): FinanceWorkflow
    {
        $this->actingAs($c['accountant'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.production-costs.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceWorkflow::where('request_key', $data['request_key'])->firstOrFail();
    }

    private function balance(array $c, string $key): string
    {
        return (string) app(FinanceWorkflows::class)->accountBasis($c['business']->id, app(FinanceSetup::class)->account($c['business']->id, $key)->id, '2026-10-27')['base']->toScale(2);
    }

    public function test_material_receipt_issue_and_unused_return_preserve_original_cost_once(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $expense = $this->expense($c);
        $data = $this->data(['source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '1000', 'quantity' => '100', 'name' => 'Export boxes', 'unit' => 'each', 'location' => 'Packing store']);
        $flow = $this->prepare($c, $data);
        $this->prepare($c, $data);
        $this->assertDatabaseCount('finance_cost_claims', 1);
        $this->postSource($c, 'finance_workflow', $flow->id);
        $lot = MaterialLot::firstOrFail();
        $state = app(FinanceProductionCosts::class)->materialState($lot);
        $this->assertTrue($state['quantity']->isEqualTo('100'));
        $this->assertSame('0.00', $this->balance($c, 'general_expense'));
        $issue = $this->prepare($c, $this->data(['kind' => 'material_issue', 'material_lot_id' => $lot->id, 'batch_id' => $c['batch']->id, 'quantity' => '20']));
        $this->postSource($c, 'finance_workflow', $issue->id);
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($c['batch'])['cost']->isEqualTo('1200'));
        $return = $this->prepare($c, $this->data(['kind' => 'material_return', 'material_lot_id' => $lot->id, 'batch_id' => $c['batch']->id, 'quantity' => '5', 'original_workflow_id' => $issue->id]));
        $this->postSource($c, 'finance_workflow', $return->id);
        $this->assertTrue(app(FinanceProductionCosts::class)->materialState($lot)['quantity']->isEqualTo('85'));
        $this->assertSame('850.00', $this->balance($c, 'materials_inventory'));
        $this->assertSame('1150.00', $this->balance($c, 'inventory'));
        $this->get(route('finance.production-costs.index'))->assertOk()->assertSee('Export boxes');
        $this->post(route('finance.production-costs.store'), $this->data(['kind' => 'material_issue', 'material_lot_id' => $lot->id, 'batch_id' => $c['batch']->id, 'quantity' => '86']))->assertStatus(422);
    }

    public function test_late_acquisition_cost_follows_waste_and_remaining_stock_without_duplicate_expense(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'SORT-COST', 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $waste = PackingWasteEntry::create(['packing_job_id' => $job->id, 'idempotency_key' => (string) Str::uuid(), 'business_id' => $c['business']->id, 'batch_id' => $c['batch']->id, 'quantity' => '2', 'reason' => 'Actual sorting loss', 'created_by' => $c['warehouse']->id]);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'waste', $waste->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $relief->id);
        $expense = $this->expense($c, '100');
        $flow = $this->prepare($c, $this->data(['kind' => 'batch_cost', 'source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '100', 'batch_id' => $c['batch']->id]));
        $this->postSource($c, 'finance_workflow', $flow->id);
        $this->assertSame('880.00', $this->balance($c, 'inventory'));
        $this->assertSame('220.00', $this->balance($c, 'waste_loss'));
        $this->assertSame('0.00', $this->balance($c, 'general_expense'));
        $this->actingAs($c['accountant'])->post(route('finance.production-costs.store'), $this->data(['kind' => 'batch_cost', 'source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '1', 'batch_id' => $c['batch']->id]))->assertStatus(422);
    }

    public function test_reviewed_allocation_release_restores_source_expense_and_consumed_cost_without_erasing_history(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'RELEASE-SORT', 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $waste = PackingWasteEntry::create(['packing_job_id' => $job->id, 'idempotency_key' => (string) Str::uuid(), 'business_id' => $c['business']->id, 'batch_id' => $c['batch']->id, 'quantity' => '2', 'reason' => 'Actual sorting loss', 'created_by' => $c['warehouse']->id]);
        $relief = app(FinanceInventoryValuation::class)->prepare($c['business'], 'waste', $waste->id, $c['accountant']->id);
        $this->postSource($c, 'inventory_relief', $relief->id);
        $expense = $this->expense($c, '100');
        $allocation = $this->prepare($c, $this->data(['kind' => 'batch_cost', 'source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '100', 'batch_id' => $c['batch']->id]));
        $this->postSource($c, 'finance_workflow', $allocation->id);
        $release = $this->prepare($c, $this->data(['kind' => 'cost_release', 'original_workflow_id' => $allocation->id]));
        $this->assertSame('0.00', $this->balance($c, 'general_expense'));
        $this->postSource($c, 'finance_workflow', $release->id);
        $this->assertSame('800.00', $this->balance($c, 'inventory'));
        $this->assertSame('200.00', $this->balance($c, 'waste_loss'));
        $this->assertSame('100.00', $this->balance($c, 'general_expense'));
        $this->assertSame('posted', $allocation->fresh()->posting_status);
        app(FinanceProductionCosts::class)->assertUnallocated($c['business']->id, 'expense', $expense->id);
        $this->actingAs($c['accountant'])->post(route('finance.production-costs.store'), $this->data(['kind' => 'cost_release', 'original_workflow_id' => $allocation->id]))->assertStatus(422);
    }

    public function test_cost_sources_are_tenant_scoped_and_changed_material_balance_requires_repreparation(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $expense = $this->expense($c);
        $other = $this->context('OTH');
        $this->actingAs($other['accountant'])->withSession(['active_business_id' => $other['business']->id])->post(route('finance.production-costs.store'), $this->data(['source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '1000', 'quantity' => '100', 'name' => 'Boxes', 'unit' => 'each', 'location' => 'Store']))->assertNotFound();
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.production-costs.store'), $this->data())->assertForbidden();
        $flow = $this->prepare($c, $this->data(['source_type' => 'expense', 'source_id' => $expense->id, 'source_account' => 'general_expense', 'base_amount' => '1000', 'quantity' => '100', 'name' => 'Boxes', 'unit' => 'each', 'location' => 'Store']));
        $this->postSource($c, 'finance_workflow', $flow->id);
        $lot = MaterialLot::where('business_id', $c['business']->id)->firstOrFail();
        $first = $this->prepare($c, $this->data(['kind' => 'material_issue', 'material_lot_id' => $lot->id, 'batch_id' => $c['batch']->id, 'quantity' => '10']));
        $this->actingAs($c['accountant'])->post(route('finance.production-costs.store'), $this->data(['kind' => 'material_issue', 'material_lot_id' => $lot->id, 'batch_id' => $c['batch']->id, 'quantity' => '10']))->assertStatus(422);
        $this->assertSame('1000.00', $this->balance($c, 'materials_inventory'));
    }
}
