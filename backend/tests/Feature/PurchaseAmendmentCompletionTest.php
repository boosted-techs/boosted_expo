<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinancePostingRequest;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseAmendment;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\FinanceDocuments;
use App\Services\FinanceSetup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseAmendmentCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_independent_purchase_changes_keep_received_and_billed_prices_and_refuse_stale_evidence(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $b = Business::create(['name' => 'Purchase Exporters', 'code' => 'POA', 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $a = User::factory()->create();
        $r = User::factory()->create();
        foreach ([$a, $r] as $u) {
            BusinessMembership::create(['business_id' => $b->id, 'user_id' => $u->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($b);
        $p = Product::create(['business_id' => $b->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg', 'active' => true]);
        $po = PurchaseOrder::create(['business_id' => $b->id, 'reference' => 'PO-ORIGINAL', 'kind' => 'market', 'vendor_name' => 'Original vendor', 'product_id' => $p->id, 'purchased_on' => '2026-10-27', 'quantity' => '10', 'unit_price' => '100', 'estimated_total' => '1000', 'currency' => 'UGX', 'status' => 'ordered', 'created_by' => $a->id, 'idempotency_key' => (string) Str::uuid()]);
        $data = ['quantity' => '12', 'unit_price' => '110', 'vendor_name' => 'Original vendor', 'expected_on' => '2026-10-28', 'agreement_reference' => 'VENDOR-AGREEMENT-01', 'reason' => 'Verified new quantity and prospective price agreed with the original vendor.', 'request_key' => (string) Str::uuid()];
        $this->actingAs($a)->withSession(['active_business_id' => $b->id])->post(route('purchases.amend', $po->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $am = PurchaseAmendment::firstOrFail();
        $this->post(route('purchases.amendments.review', [$am->id, 'approve']), ['note' => 'Independent approval is required for this purchase change.'])->assertStatus(422);
        $this->actingAs($r)->post(route('purchases.amendments.review', [$am->id, 'approve']), ['note' => 'Verified supplier agreement and outstanding original purchase obligations.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('applied', $am->fresh()->status);
        $this->assertEquals('12', $po->fresh()->quantity);
        $batch = StockBatch::create(['business_id' => $b->id, 'product_id' => $p->id, 'batch_code' => 'BATCH-POA', 'received_on' => '2026-10-27']);
        GoodsReceipt::create(['business_id' => $b->id, 'reference' => 'GR-POA', 'purchase_order_id' => $po->id, 'batch_id' => $batch->id, 'received_quantity' => '3', 'accepted_quantity' => '3', 'rejected_quantity' => '0', 'received_on' => '2026-10-27', 'posted_by' => $a->id, 'idempotency_key' => (string) Str::uuid()]);
        $po->refresh();
        $po->update(['status' => 'part_received']);
        $this->actingAs($a)->post(route('purchases.amend', $po->id), [...$data, 'unit_price' => '200', 'request_key' => (string) Str::uuid()])->assertStatus(422);
        $bill = app(FinanceDocuments::class)->supplierBill($b, $po, ['vendor_invoice_reference' => 'ORIGINAL-BILL', 'vendor_invoice_date' => '2026-10-27'], $a->id);
        $this->post(route('finance.reviews.request', ['type' => 'supplier_bill', 'source' => $bill->id]), ['posting_on' => '2026-10-27', 'basis_notes' => 'Original receipt quantity and price independently supported.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::where('source_type', 'supplier_bill')->where('source_id', $bill->id)->firstOrFail();
        $this->actingAs($r)->post(route('finance.reviews.approve', $review->id), ['notes' => 'Verified original received produce and original supplier invoice.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($a)->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('purchases.amend', $po->id), [...$data, 'unit_price' => '200', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $second = PurchaseAmendment::latest('id')->firstOrFail();
        $this->actingAs($r)->post(route('purchases.amendments.review', [$second->id, 'approve']), ['note' => 'Confirmed price applies only after previously received goods were billed.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals('330', $bill->fresh()->total);
        $this->assertEquals('110', $bill->receipt_snapshot['unit_price']);
        $this->assertEquals('200', $po->fresh()->unit_price);
        $this->get(route('purchases.show', $po->id))->assertOk()->assertSee('Agreement history');
        $this->actingAs($a)->post(route('purchases.amend', $po->id), [...$data, 'quantity' => '2', 'unit_price' => '200', 'request_key' => (string) Str::uuid()])->assertStatus(422);
    }
}
