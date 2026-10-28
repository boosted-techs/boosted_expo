<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\TeamMembershipEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceUsabilityTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code = 'UI', string $role = 'owner'): array
    {
        $business = Business::create(['name' => $code.' Exports', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch']);
        $user = User::factory()->create();
        $member = BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'name' => $code.' Avocado', 'sku' => 'AVO', 'base_unit' => 'kg', 'reorder_point' => '40', 'active' => true]);

        return compact('business', 'user', 'member', 'product');
    }

    private function signIn(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_business_id' => $context['business']->id]);
    }

    private function purchase(array $c, string $status = 'ordered'): PurchaseOrder
    {
        return PurchaseOrder::create(['business_id' => $c['business']->id, 'product_id' => $c['product']->id, 'reference' => 'PO-'.Str::uuid(), 'kind' => 'market', 'vendor_name' => 'Nakasero Traders', 'purchased_on' => '2026-10-27', 'quantity' => '10', 'unit_price' => '5000', 'estimated_total' => '50000', 'currency' => 'UGX', 'status' => $status, 'idempotency_key' => Str::uuid(), 'created_by' => $c['user']->id]);
    }

    public function test_stock_search_sort_and_pagination_cover_all_records_without_crossing_businesses(): void
    {
        $a = $this->context('A');
        $b = $this->context('B');
        foreach (range(1, 31) as $number) {
            StockBatch::create(['business_id' => $a['business']->id, 'product_id' => $a['product']->id, 'batch_code' => 'BATCH-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'received_on' => '2026-10-27']);
        }
        StockBatch::create(['business_id' => $b['business']->id, 'product_id' => $b['product']->id, 'batch_code' => 'OUTSIDE', 'received_on' => '2026-10-27']);
        $this->signIn($a)->get(route('stock.index', ['tables' => ['stock_batches__page' => ['search' => 'BATCH-031']]]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->total() === 1 && $rows->first()->batch_code === 'BATCH-031')->assertDontSee('OUTSIDE');
        $this->get(route('stock.index', ['tables' => ['stock_batches__page' => ['sort' => 'batch_code', 'direction' => 'asc', 'size' => 10]], 'page' => 2]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->total() === 31 && $rows->perPage() === 10 && $rows->first()->batch_code === 'BATCH-011');
        $this->get(route('stock.index', ['tables' => ['stock_batches__page' => ['sort' => 'id; DROP TABLE products', 'size' => 99999]]]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->perPage() === 25);
        $otherProduct = Product::create(['business_id' => $a['business']->id, 'name' => 'Zucchini', 'sku' => 'ZUC', 'base_unit' => 'kg', 'active' => true]);
        StockBatch::create(['business_id' => $a['business']->id, 'product_id' => $otherProduct->id, 'batch_code' => 'ZUC-BATCH', 'received_on' => '2026-10-27']);
        $this->get(route('stock.index', ['tables' => ['stock_batches__page' => ['sort' => 'product', 'direction' => 'desc']]]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->first()->batch_code === 'ZUC-BATCH');
        foreach (['BATCH-001' => '100', 'BATCH-031' => '5'] as $code => $quantity) {
            $batch = StockBatch::query()->where('business_id', $a['business']->id)->where('batch_code', $code)->firstOrFail();
            $batch->update(['is_quarantined' => $code === 'BATCH-001']);
            StockMovement::create(['business_id' => $a['business']->id, 'batch_id' => $batch->id, 'movement_type' => 'receipt', 'quantity' => $quantity, 'unit' => 'kg', 'source_type' => 'test', 'source_id' => $batch->id, 'occurred_on' => '2026-10-27', 'created_by' => $a['user']->id]);
        }
        $this->get(route('stock.index', ['tables' => ['stock_batches__page' => ['sort' => 'available_balance', 'direction' => 'desc']]]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->first()->batch_code === 'BATCH-031');
        $this->get(route('stock.index', ['tables' => ['stock_batches__page' => ['sort' => 'unpacked_balance', 'direction' => 'desc']]]))->assertOk()->assertViewHas('batches', fn ($rows) => $rows->first()->batch_code === 'BATCH-001');
        $this->get(route('stock.low-stock'))->assertOk()->assertSee('A Avocado')->assertDontSee('B Avocado');
    }

    public function test_team_history_formats_a_saved_timestamp_without_a_server_error(): void
    {
        $c = $this->context();
        TeamMembershipEvent::create(['business_id' => $c['business']->id, 'membership_id' => $c['member']->id, 'actor_id' => $c['user']->id, 'action' => 'role_changed', 'previous_role' => 'admin', 'new_role' => 'owner', 'previous_active' => true, 'new_active' => true, 'reason' => 'Verified owner role change', 'created_at' => '2026-10-27 09:30:00']);
        $this->signIn($c)->get(route('team.activity', 'history'))->assertOk()->assertSee('27 Oct 2026 09:30');
    }

    public function test_unused_purchase_can_be_archived_and_restored_without_deleting_history(): void
    {
        $c = $this->context();
        $purchase = $this->purchase($c);
        $this->signIn($c)->delete(route('purchases.archive', $purchase), ['reason' => 'Duplicate unused purchase entry'])->assertRedirect(route('purchases.archived'));
        $this->assertSoftDeleted('purchase_orders', ['id' => $purchase->id]);
        $this->get(route('purchases.index'))->assertOk()->assertDontSee($purchase->reference);
        $this->get(route('purchases.archived'))->assertOk()->assertSee($purchase->reference);
        $this->post(route('purchases.restore', $purchase->id))->assertRedirect(route('purchases.show', $purchase->id));
        $this->assertNotSoftDeleted('purchase_orders', ['id' => $purchase->id]);
        $this->get(route('purchases.show', $purchase->id))->assertOk()->assertSee('PURCHASE SLIP')->assertSee('data-print-target="#purchase-slip"', false);
    }

    public function test_archive_protects_completed_records_permissions_and_business_boundaries(): void
    {
        $a = $this->context('A');
        $b = $this->context('B');
        $viewer = $this->context('VIEW', 'viewer');
        $completed = $this->purchase($a, 'received');
        $unused = $this->purchase($a);
        $foreign = $this->purchase($b);
        $this->signIn($a)->delete(route('purchases.archive', $completed), ['reason' => 'Must retain received history'])->assertUnprocessable();
        $this->delete(route('purchases.archive', $foreign), ['reason' => 'Cross-business request rejected'])->assertNotFound();
        $this->signIn($viewer)->delete(route('purchases.archive', $unused), ['reason' => 'Viewer cannot archive purchases'])->assertForbidden();
        $this->assertNotSoftDeleted('purchase_orders', ['id' => $completed->id]);
        $this->assertNotSoftDeleted('purchase_orders', ['id' => $unused->id]);
    }

    public function test_focused_staff_activities_enforce_manager_permissions(): void
    {
        $c = $this->context('OWN');
        $viewer = $this->context('VIEW', 'viewer');
        $this->signIn($c)->get(route('people.index'))->assertOk()->assertSee('Choose the activity')->assertDontSee('name="staff_code"', false);
        $this->get(route('people.activity', 'register'))->assertOk()->assertSee('name="staff_code"', false);
        $this->get(route('staff.activity', 'schedule'))->assertOk()->assertSee('Schedule a staff shift');
        $this->signIn($viewer)->get(route('people.activity', 'register'))->assertForbidden();
        $this->get(route('staff.activity', 'schedule'))->assertForbidden();
    }

    public function test_catalogue_archives_are_reversible_and_tenant_scoped(): void
    {
        $a = $this->context('A');
        $b = $this->context('B');
        $viewer = $this->context('VIEW', 'viewer');
        $this->signIn($a)->post(route('records.archive', ['type' => 'product', 'record' => $a['product']->id, 'action' => 'archive']), ['reason' => 'No longer used for new orders'])->assertRedirect();
        $this->assertFalse($a['product']->fresh()->active);
        $this->post(route('records.archive', ['type' => 'product', 'record' => $a['product']->id, 'action' => 'restore']), ['reason' => 'Available for orders again'])->assertRedirect();
        $this->assertTrue($a['product']->fresh()->active);
        $this->post(route('records.archive', ['type' => 'product', 'record' => $b['product']->id, 'action' => 'archive']), ['reason' => 'Outside the current company'])->assertNotFound();
        $this->signIn($viewer)->post(route('records.archive', ['type' => 'product', 'record' => $a['product']->id, 'action' => 'archive']), ['reason' => 'A viewer cannot archive products'])->assertForbidden();
        $this->assertTrue($a['product']->fresh()->active);
    }
}
