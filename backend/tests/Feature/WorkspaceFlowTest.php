<?php

namespace Tests\Feature;

use App\Mail\SupplierRequestMail;
use App\Models\Business;
use App\Models\BusinessDestination;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesRequest;
use App\Models\Supplier;
use App\Models\SupplyRequest;
use App\Models\User;
use App\Services\SupplierQuoting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $code = 'A', string $role = 'owner'): array
    {
        $business = Business::create(['name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch']);
        $user = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg']);
        $customer = Customer::create(['business_id' => $business->id, 'customer_code' => 'C1', 'name' => 'Buyer', 'country_code' => 'AE', 'currency' => 'USD']);

        return [$business, $user, $product, $customer];
    }

    public function test_sales_references_destinations_and_repeat_submissions_are_safe(): void
    {
        [$business,$owner,$product,$customer] = $this->business();
        $this->actingAs($owner)->postJson(route('destinations.store'), ['name' => 'Dubai cargo', 'country_code' => 'AE'])->assertOk()->assertJsonPath('selection.label', 'Dubai cargo');
        $destination = BusinessDestination::firstOrFail();
        $data = ['customer_id' => $customer->id, 'destination_id' => $destination->id, 'submission_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => '10']]];
        $this->post(route('sales-requests.store'), $data)->assertRedirect();
        $this->post(route('sales-requests.store'), $data)->assertRedirect();
        $record = SalesRequest::firstOrFail();
        $this->assertStringStartsWith('CRQ-', $record->reference);
        $this->assertSame('Dubai cargo', $record->destination);
        $this->assertDatabaseCount('sales_requests', 1);
        $data['submission_key'] = (string) Str::uuid();
        $data['reference'] = 'CLIENT-2026-01';
        $this->post(route('sales-requests.store'), $data)->assertRedirect();
        $data['submission_key'] = (string) Str::uuid();
        $data['reference'] = 'client-2026-01';
        $this->post(route('sales-requests.store'), $data)->assertSessionHasErrors('reference');
        $this->assertDatabaseCount('sales_requests', 2);
        $this->get(route('sales-requests.show', ['record' => $record->id, 'activity' => 'approval']))->assertOk()->assertDontSee('Quotation history');
        $this->get(route('sales-requests.show', ['record' => $record->id, 'activity' => 'quotes']))->assertOk()->assertDontSee('Approve quantity-only work');
    }

    public function test_request_rejects_duplicates_foreign_destinations_and_forbidden_actions(): void
    {
        [$business,$owner,$product,$customer] = $this->business();
        [$otherBusiness] = $this->business('B');
        $foreign = BusinessDestination::create(['business_id' => $otherBusiness->id, 'name' => 'Other', 'name_key' => 'other']);
        $data = ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => '10']]];
        $this->actingAs($owner)->post(route('sales-requests.store'), [...$data, 'destination_id' => $foreign->id])->assertNotFound();
        $this->post(route('sales-requests.store'), [...$data, 'items' => [$data['items'][0], $data['items'][0]]])->assertSessionHasErrors('items.0.product_id');
        $viewer = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->withSession(['active_business_id' => $business->id])->get(route('destinations.create'))->assertForbidden();
        $this->post(route('sales-requests.store'), $data)->assertForbidden();
        $this->assertDatabaseCount('sales_requests', 0);
    }

    public function test_multi_item_supplier_reply_awards_each_product_and_freezes_only_the_selected_line(): void
    {
        Mail::fake();
        [$business,$owner,$product] = $this->business();
        $second = Product::create(['business_id' => $business->id, 'sku' => 'BOX', 'name' => 'Boxes', 'base_unit' => 'each']);
        $supplier = Supplier::create(['business_id' => $business->id, 'supplier_code' => 'S1', 'name' => 'Farm', 'kind' => 'company', 'email' => 'farm@example.test']);
        $this->actingAs($owner)->post(route('supply-requests.store'), ['items' => [['product_id' => $product->id, 'quantity' => '10'], ['product_id' => $second->id, 'quantity' => '5']], 'currency' => 'UGX', 'response_deadline' => now()->addDays(2)->toDateTimeString()])->assertRedirect();
        $rfq = SupplyRequest::firstOrFail();
        $this->assertSame(2, $rfq->items()->count());
        $this->post(route('supply-requests.issue', $rfq->id), ['supplier_ids' => [$supplier->id]])->assertRedirect();
        $token = null;
        Mail::assertSent(SupplierRequestMail::class, function ($mail) use (&$token) {
            $token = $mail->plainToken;

            return true;
        });
        $lines = $rfq->items()->orderBy('id')->get();
        $this->get(route('supplier-offer.show', $token))->assertOk()->assertSee('Avocado')->assertSee('Boxes');
        $this->post(route('supplier-offer.store', $token), ['items' => [['supply_request_item_id' => $lines[0]->id, 'quantity' => '', 'unit_price' => ''], ['supply_request_item_id' => $lines[1]->id, 'quantity' => '6', 'unit_price' => '100']]])->assertSessionHasErrors('quantity')->assertSessionHasInput('items.1.quantity', '6');
        $this->assertDatabaseCount('supplier_quotes', 0);
        $offers = ['items' => [['supply_request_item_id' => $lines[0]->id, 'quantity' => '10', 'unit_price' => '5000'], ['supply_request_item_id' => $lines[1]->id, 'quantity' => '5', 'unit_price' => '1000']]];
        $this->post(route('supplier-offer.store', $token), $offers)->assertRedirect();
        $quotes = $rfq->quotes()->orderBy('supply_request_item_id')->get();
        $this->assertCount(2, $quotes);
        $this->post(route('supply-requests.award', ['rfq' => $rfq->id, 'quote' => $quotes[0]->id]))->assertRedirect();
        $this->assertSame('part_awarded', $rfq->fresh()->status);
        $product->update(['active' => false]);
        $this->get(route('supplier-offer.show', $token))->assertOk()->assertSee('Boxes');
        $product->update(['active' => true]);
        $this->get(route('supplier-offer.show', $token))->assertOk()->assertSee('Selected');
        $this->post(route('supplier-offer.store', $token), ['items' => [$offers['items'][0]]])->assertStatus(422);
        $this->post(route('supplier-offer.store', $token), ['items' => [[...$offers['items'][1], 'unit_price' => '900']]])->assertRedirect();
        $this->post(route('supply-requests.award', ['rfq' => $rfq->id, 'quote' => $quotes[1]->id]))->assertRedirect();
        $this->assertSame('awarded', $rfq->fresh()->status);
        $this->assertDatabaseHas('purchase_orders', ['product_id' => $second->id, 'quantity' => '5', 'unit_price' => '900']);
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->get(route('supplier-offer.show', $token))->assertNotFound();
        $purchase = PurchaseOrder::firstOrFail();
        $this->delete(route('purchases.archive', $purchase->id), ['reason' => 'Remove selected purchase'])->assertStatus(422);
    }

    public function test_multi_item_offers_reject_foreign_lines_and_roll_back_invalid_batches(): void
    {
        [$business,$owner,$product] = $this->business();
        $supplier = Supplier::create(['business_id' => $business->id, 'supplier_code' => 'S1', 'name' => 'Farm', 'kind' => 'company']);
        $attrs = ['business_id' => $business->id, 'product_id' => $product->id, 'quantity' => '10', 'currency' => 'UGX', 'response_deadline' => now()->addDay(), 'status' => 'sent', 'created_by' => $owner->id];
        $rfq = SupplyRequest::create([...$attrs, 'reference' => 'R1']);
        $second = SupplyRequest::create([...$attrs, 'reference' => 'R2']);
        $quotes = app(SupplierQuoting::class);
        try {
            $quotes->recordMany($rfq, $supplier, ['items' => [['supply_request_item_id' => $rfq->items()->first()->id, 'quantity' => '5', 'unit_price' => '100'], ['supply_request_item_id' => $second->items()->first()->id, 'quantity' => '5', 'unit_price' => '100']]], 'staff_entry');
            $this->fail('A line from another request was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('supplier_quotes', 0);
            $this->assertDatabaseCount('supplier_quote_revisions', 0);
        }
    }

    public function test_activity_pages_render_only_the_chosen_task_and_keep_authorization(): void
    {
        [$business,$owner] = $this->business();
        $this->actingAs($owner)->get(route('finance.treasury.index'))->assertOk()->assertSee('activity-cards', false);
        $this->get(route('finance.treasury.index', ['workspace_panel' => 'cash-count-history']))->assertOk()->assertSee('Cash count history')->assertDontSee('name="amount"', false)->assertDontSee('Recurring bills to check');
        $this->get(route('finance.treasury.index', ['workspace_panel' => 'not-a-task']))->assertNotFound();
        $viewer = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->withSession(['active_business_id' => $business->id])->get(route('finance.treasury.index', ['workspace_panel' => 'record-a-money-event']))->assertForbidden();
    }
}
