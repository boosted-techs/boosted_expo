<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\FinanceCutover;
use App\Models\FinanceOpeningItem;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\Product;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceCostBasis;
use App\Services\FinanceCutovers;
use App\Services\FinanceInventoryReconciliation;
use App\Services\FinanceOpeningItems;
use App\Services\FinanceOperations;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceStatements;
use App\Services\FinanceWorkflowCorrections;
use App\Services\FinanceWorkflows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CutoverCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        Storage::fake('local');
        $b = Business::create(['name' => 'Existing Ugandan Exporters', 'code' => 'CUT', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $a = User::factory()->create();
        $r = User::factory()->create();
        foreach ([$a, $r] as $u) {
            BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($b);
        Customer::create(['business_id' => $b->id, 'customer_code' => 'BUYER', 'name' => 'Original importer', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);
        Supplier::create(['business_id' => $b->id, 'supplier_code' => 'GROWER', 'name' => 'Original grower', 'kind' => 'farmer', 'active' => true]);
        Product::create(['business_id' => $b->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg', 'active' => true]);
        StockLocation::create(['business_id' => $b->id, 'name' => 'Packhouse', 'active' => true]);
        StaffPerson::create(['business_id' => $b->id, 'staff_code' => 'BUYER-STAFF', 'name' => 'Market buyer', 'kind' => 'casual', 'active' => true]);
        $this->actingAs($a)->withSession(['active_business_id' => $b->id]);

        return compact('b', 'a', 'r');
    }

    private function row(array $data): array
    {
        return [...array_fill_keys(FinanceCutovers::COLUMNS, ''), 'notes' => 'Signed physical count, original account statement or agreed source document verified.', ...$data];
    }

    private function file(array $rows): UploadedFile
    {
        $file = fopen('php://temp', 'w+');
        fputcsv($file, FinanceCutovers::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, array_values($row), ',', '"', '');
        }rewind($file);
        $text = stream_get_contents($file);
        fclose($file);

        return UploadedFile::fake()->createWithContent('signed-opening.csv', $text);
    }

    private function postFlow(array $c, FinanceWorkflow $flow): void
    {
        $this->actingAs($c['a'])->post(route('finance.reviews.request', ['type' => 'finance_workflow', 'source' => $flow->id]), ['posting_on' => $flow->event_on->toDateString(), 'exchange_rate_id' => $flow->exchange_rate_id, 'basis_notes' => 'Signed original opening sources and independently prepared controls verified.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'finance_workflow')->where('source_id', $flow->id)->firstOrFail();
        $this->actingAs($c['r'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently verified all imported sources and opening control balances.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['a'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function rows(): array
    {
        $rows = [$this->row(['type' => 'stock', 'reference' => 'OPEN-BATCH', 'item_code' => 'AVO', 'currency' => 'UGX', 'amount' => '1000', 'quantity' => '10', 'location' => 'Packhouse']), $this->row(['type' => 'cash', 'reference' => 'OPEN-CASH', 'item_code' => 'cash', 'currency' => 'UGX', 'amount' => '500']), $this->row(['type' => 'customer_debt', 'reference' => 'OLD-INVOICE', 'party_code' => 'BUYER', 'currency' => 'UGX', 'amount' => '300', 'due_on' => '2026-10-30']), $this->row(['type' => 'supplier_debt', 'reference' => 'OLD-BILL', 'party_code' => 'GROWER', 'currency' => 'UGX', 'amount' => '400', 'due_on' => '2026-11-01']), $this->row(['type' => 'staff_advance', 'reference' => 'OLD-ADVANCE', 'party_code' => 'BUYER-STAFF', 'currency' => 'UGX', 'amount' => '100', 'due_on' => '2026-10-28']), $this->row(['type' => 'customer_deposit', 'reference' => 'OLD-DEPOSIT', 'party_code' => 'BUYER', 'currency' => 'UGX', 'amount' => '50', 'due_on' => '2026-10-31']), $this->row(['type' => 'order', 'reference' => 'BUYER-LINE-1', 'order_reference' => 'BUYER-PENDING-01', 'party_code' => 'BUYER', 'item_code' => 'AVO', 'quantity' => '5', 'due_on' => '2026-10-29']), $this->row(['type' => 'purchase_supplier', 'reference' => 'PENDING-PO', 'party_code' => 'GROWER', 'item_code' => 'AVO', 'currency' => 'UGX', 'amount' => '100', 'quantity' => '5', 'due_on' => '2026-10-28', 'order_reference' => 'BUYER-PENDING-01'])];
        foreach (['inventory' => '1000', 'cash' => '500', 'receivable' => '300', 'payable' => '-400', 'staff_advances' => '100', 'customer_deposits' => '-50', 'opening_equity' => '-1450'] as $key => $value) {
            $rows[] = $this->row(['type' => 'control', 'reference' => 'CONTROL-'.$key, 'item_code' => $key, 'amount' => $value]);
        }

        return $rows;
    }

    public function test_cutover_import_posts_real_opening_sources_once_and_requires_independent_reconciled_acceptance(): void
    {
        $c = $this->context();
        $data = ['effective_on' => '2026-10-26', 'external_reference' => 'SIGNED-CLOSING-POSITION', 'reason' => 'Signed original inventory, trade, cash and advance accounts reconciled at cutover.', 'request_key' => (string) Str::uuid()];
        $this->post(route('finance.cutover.store'), [...$data, 'file' => $this->file($this->rows())])->assertRedirect()->assertSessionHasNoErrors();
        $cutover = FinanceCutover::firstOrFail();
        $flow = FinanceWorkflow::findOrFail($cutover->workflow_id);
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('finance_opening_items', 0);
        $this->postFlow($c, $flow);
        $this->assertSame('posted', $cutover->fresh()->status);
        $this->assertDatabaseCount('finance_opening_items', 4);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('order_supplies', 1);
        $reconciliation = app(FinanceInventoryReconciliation::class)->report($c['b'], '2026-10-26');
        $this->assertTrue($reconciliation['matches'], implode(' ', $reconciliation['issues']));
        $batch = StockBatch::where('batch_code', 'OPEN-BATCH')->firstOrFail();
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($batch)['cost']->isEqualTo('1000'));
        $this->post(route('finance.cutover.accept', $cutover->id), ['note' => 'Own imported opening data cannot be independently accepted by its preparer.'])->assertStatus(422);
        $this->actingAs($c['r'])->post(route('finance.cutover.accept', $cutover->id), ['note' => 'Independently confirmed the signed source statements, actual opening quantities and every control total.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted', $cutover->fresh()->status);
        $this->actingAs($c['a'])->post(route('finance.cutover.store'), [...$data, 'file' => $this->file($this->rows())])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_cutovers', 1);
        $item = FinanceOpeningItem::where('kind', 'customer_debt')->firstOrFail();
        $this->post(route('finance.opening-items.settle', $item->id), ['kind' => 'opening_item_settlement', 'event_on' => '2026-10-27', 'amount' => '100', 'account_key' => 'cash', 'external_reference' => 'OLD-DEBT-RECEIPT', 'reason' => 'Actual cash received against the original opening invoice balance.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $settlement = FinanceWorkflow::where('kind', 'opening_item_settlement')->firstOrFail();
        $this->postFlow($c, $settlement);
        $state = app(FinanceOpeningItems::class)->position($item);
        $this->assertTrue($state['amount']->isEqualTo('200'));
        $this->assertTrue($state['base']->isEqualTo('200'));
        $cash = app(FinanceSetup::class)->account($c['b']->id, 'cash');
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['b']->id, $cash->id, '2026-10-27')['units']->isEqualTo('600'));
        $this->get(route('finance.cutover.index'))->assertOk()->assertSee('OLD-INVOICE');
        $this->get(route('finance.cutover.evidence', $cutover->id))->assertOk();
        $item->update(['customer_id' => null]);
        $this->get(route('finance.cutover.index'))->assertStatus(422);
    }

    public function test_foreign_opening_revaluation_and_partial_cash_settlement_preserve_units_and_release_only_settled_fx(): void
    {
        $c = $this->context();
        $old = app(FinanceRates::class)->propose($c['b'], ['currency' => 'USD', 'rate_to_base' => '3700', 'effective_on' => '2026-10-26', 'source_note' => 'Verified signed opening foreign debt translation basis.'], $c['a']->id);
        app(FinanceRates::class)->approve($old, $c['r']->id);
        $new = app(FinanceRates::class)->propose($c['b'], ['currency' => 'USD', 'rate_to_base' => '3800', 'effective_on' => '2026-10-27', 'source_note' => 'Independently agreed subsequent foreign reporting rate.'], $c['a']->id);
        app(FinanceRates::class)->approve($new, $c['r']->id);
        $rows = [$this->row(['type' => 'customer_debt', 'reference' => 'USD-OLD-INVOICE', 'party_code' => 'BUYER', 'currency' => 'USD', 'amount' => '100', 'rate_id' => (string) $old->id, 'due_on' => '2026-10-30']), $this->row(['type' => 'control', 'reference' => 'AR-CONTROL', 'item_code' => 'receivable', 'amount' => '370000']), $this->row(['type' => 'control', 'reference' => 'EQUITY-CONTROL', 'item_code' => 'opening_equity', 'amount' => '-370000'])];
        $this->post(route('finance.cutover.store'), ['effective_on' => '2026-10-26', 'external_reference' => 'SIGNED-USD-OPENING', 'reason' => 'Original importer invoice and signed foreign opening debt independently reconciled.', 'request_key' => (string) Str::uuid(), 'file' => $this->file($rows)])->assertRedirect()->assertSessionHasNoErrors();
        $cutover = FinanceCutover::firstOrFail();
        $this->postFlow($c, FinanceWorkflow::findOrFail($cutover->workflow_id));
        $item = FinanceOpeningItem::firstOrFail();
        $this->post(route('finance.opening-items.revalue', $item->id), ['event_on' => '2026-10-27', 'exchange_rate_id' => $new->id, 'external_reference' => 'USD-REVALUE-001', 'reason' => 'Verified outstanding USD units and independently approved reporting rate.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->postFlow($c, FinanceWorkflow::where('kind', 'opening_item_revaluation')->firstOrFail());
        $position = app(FinanceOpeningItems::class)->position($item);
        $this->assertTrue($position['amount']->isEqualTo('100'));
        $this->assertTrue($position['base']->isEqualTo('380000'));
        $this->assertTrue($position['unrealized']->isEqualTo('10000'));
        $bank = app(FinanceBankProfiles::class)->create($c['b'], ['label' => 'Actual USD bank', 'institution' => 'Exporter bank', 'currency' => 'USD'], $c['a']->id);
        $this->post(route('finance.opening-items.settle', $item->id), ['kind' => 'opening_item_settlement', 'event_on' => '2026-10-27', 'amount' => '40', 'exchange_rate_id' => $new->id, 'bank_profile_id' => $bank->id, 'external_reference' => 'USD-RECEIPT-001', 'reason' => 'Actual importer part receipt against the original USD opening debt.', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->postFlow($c, FinanceWorkflow::where('kind', 'opening_item_settlement')->firstOrFail());
        $position = app(FinanceOpeningItems::class)->position($item);
        $this->assertTrue($position['amount']->isEqualTo('60'));
        $this->assertTrue($position['base']->isEqualTo('228000'));
        $this->assertTrue($position['unrealized']->isEqualTo('6000'));
        $this->assertTrue(app(FinanceWorkflows::class)->accountBasis($c['b']->id, $bank->account_id, '2026-10-27')['base']->isEqualTo('152000'));
        $this->get(route('finance.reports.index', ['from' => '2026-10-26', 'to' => '2026-10-27']))->assertOk()->assertSee('USD-OLD-INVOICE')->assertSee('228000');
    }

    public function test_unbalanced_opening_file_is_rejected_without_creating_sources(): void
    {
        $c = $this->context();
        $rows = $this->rows();
        $rows[0]['amount'] = '1001';
        $this->post(route('finance.cutover.store'), ['effective_on' => '2026-10-26', 'external_reference' => 'WRONG-CONTROL-001', 'reason' => 'This intentionally mismatches the signed opening inventory control total.', 'request_key' => (string) Str::uuid(), 'file' => $this->file($rows)])->assertStatus(422);
        $this->assertDatabaseCount('finance_cutovers', 0);
        $this->assertDatabaseCount('finance_workflows', 0);
        $this->assertDatabaseCount('stock_batches', 0);
    }

    public function test_opening_staff_advance_application_and_reviewed_correction_restore_only_actual_unpaid_sources(): void
    {
        $c = $this->context();
        $this->post(route('finance.cutover.store'), ['effective_on' => '2026-10-26', 'external_reference' => 'SIGNED-CUTOVER', 'reason' => 'Signed actual stock, trade and advance opening sources verified.', 'request_key' => (string) Str::uuid(), 'file' => $this->file($this->rows())])->assertRedirect()->assertSessionHasNoErrors();
        $cut = FinanceCutover::firstOrFail();
        $this->postFlow($c, FinanceWorkflow::findOrFail($cut->workflow_id));
        $staff = StaffPerson::where('staff_code', 'BUYER-STAFF')->firstOrFail();
        $expense = app(FinanceOperations::class)->prepare($c['b'], ['kind' => 'expense', 'staff_person_id' => $staff->id, 'currency' => 'UGX', 'amount' => '80', 'event_on' => '2026-10-27', 'description' => 'Actual staff-paid market transport supported by signed expense receipt.', 'category' => 'transport', 'external_reference' => 'CLAIM-NEW-01', 'idempotency_key' => (string) Str::uuid()], $c['a']->id);
        $this->actingAs($c['a'])->post(route('finance.reviews.request', ['type' => 'money_operation', 'source' => $expense->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Actual staff claim and original transport evidence checked.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'money_operation')->where('source_id', $expense->id)->firstOrFail();
        $this->actingAs($c['r'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independent check of original staff expense and its actual amount.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['a'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $item = FinanceOpeningItem::where('kind', 'staff_advance')->firstOrFail();
        $flow = app(FinanceOpeningItems::class)->prepare($c['b'], $item, 'opening_item_apply', ['amount' => '60', 'target_type' => 'expense', 'target_id' => $expense->id, 'event_on' => '2026-10-27', 'external_reference' => 'SIGNED-APPLICATION', 'reason' => 'Apply the real carried staff advance against the independently posted claim.', 'request_key' => (string) Str::uuid()], $c['a']->id);
        $this->postFlow($c, $flow);
        $this->assertTrue(app(FinanceOpeningItems::class)->position($item)['amount']->isEqualTo('40'));
        $this->assertTrue(app(FinanceOperations::class)->remaining($expense, false)->isEqualTo('20'));
        $correction = app(FinanceWorkflowCorrections::class)->prepare($c['b'], $flow, ['event_on' => '2026-10-27', 'external_reference' => 'SIGNED-UNAPPLY', 'reason' => 'Independent correction of an incorrect advance-to-claim assignment.', 'request_key' => (string) Str::uuid()], $c['a']->id);
        $this->postFlow($c, $correction);
        $this->assertTrue(app(FinanceOpeningItems::class)->position($item)['amount']->isEqualTo('100'));
        $this->assertTrue(app(FinanceOperations::class)->remaining($expense, false)->isEqualTo('80'));
        $report = app(FinanceStatements::class)->report($c['b'], '2026-10-27', '2026-10-27');
        $this->assertSame('80.00', $report['expense']);
    }
}
