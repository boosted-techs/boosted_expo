<?php

namespace Tests\Feature;

use App\Models\AdvanceRequest;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\GoodsReceipt;
use App\Models\MarketAccountability;
use App\Models\MarketTrip;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\TradeCollectionCase;
use App\Models\User;
use App\Services\FinanceDocuments;
use App\Services\FinanceOperations;
use App\Services\FinanceSetup;
use App\Services\FinanceTradeControls;
use App\Services\SalesWorkflow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TradeAccountabilityCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $code = 'TRD'): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $business = Business::create(['name' => 'Trade Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }app(FinanceSetup::class)->activate($business);
        $staff = StaffPerson::create(['business_id' => $business->id, 'staff_code' => 'BUYER', 'name' => 'Market buyer', 'kind' => 'employee', 'active' => true]);
        $customer = Customer::create(['business_id' => $business->id, 'customer_code' => 'CUSTOMER', 'name' => 'Export buyer', 'currency' => 'UGX', 'country_code' => 'AE', 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg', 'active' => true]);
        $this->actingAs($owner)->withSession(['active_business_id' => $business->id]);

        return compact('business', 'owner', 'reviewer', 'staff', 'customer', 'product');
    }

    private function postSource(array $c, string $type, $source): void
    {
        $this->actingAs($c['owner'])->post(route('finance.reviews.request', ['type' => $type, 'source' => $source->id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $source->exchange_rate_id, 'basis_notes' => 'Verified source amount, business purpose and actual evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', $type)->where('source_id', $source->id)->firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Independently checked the actual source and its agreement.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($c['owner'])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function moneyData(array $extra = []): array
    {
        return [...['kind' => 'staff_advance', 'currency' => 'UGX', 'amount' => '1000', 'event_on' => '2026-10-27', 'description' => 'Verified staff buying money or reimbursement', 'external_reference' => 'MKT-'.Str::ulid(), 'cash_account_key' => 'cash', 'idempotency_key' => (string) Str::uuid()], ...$extra];
    }

    private function money(array $c, array $data): FinanceOperation
    {
        $row = app(FinanceOperations::class)->prepare($c['business'], $data, $c['owner']->id);
        $this->postSource($c, 'money_operation', $row);

        return $row->fresh();
    }

    private function quote(array $c): object
    {
        $s = app(SalesWorkflow::class);
        $request = $s->createRequest($c['business']->id, ['customer_id' => $c['customer']->id, 'items' => [['product_id' => $c['product']->id, 'quantity' => '10']]], $c['owner']->id);
        $quote = $s->draft($c['business']->id, $request->id, $c['owner']->id);
        $s->updateDraft($quote, ['currency' => 'UGX', 'discount_percent' => 0, 'tax_percent' => 0, 'items' => [['product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '100']]]);

        return $quote->fresh();
    }

    public function test_default_terms_are_versioned_and_issued_quotation_keeps_its_agreed_days(): void
    {
        $c = $this->context();
        $data = ['customer_id' => $c['customer']->id, 'currency' => 'UGX', 'payment_days' => '7', 'effective_on' => '2026-10-27', 'agreement' => 'Buyer payment agreement approved before issuing the quotation.'];
        $this->post(route('finance.trade.terms'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $quote = $this->quote($c);
        app(SalesWorkflow::class)->issue($quote, $c['owner']->id);
        $this->assertSame(7, $quote->fresh()->payment_terms_days);
        $this->post(route('finance.trade.terms'), [...$data, 'payment_days' => '30', 'agreement' => 'Revised agreement applies to subsequent quotations and invoices.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(7, $quote->fresh()->payment_terms_days);
        $this->assertSame(30, $this->quote($c)->payment_terms_days);
        $this->assertDatabaseCount('partner_finance_terms', 2);
        $this->get(route('finance.trade.index'))->assertOk()->assertSee('Customer and supplier terms');
    }

    public function test_advance_request_approval_and_staff_limit_prevent_over_disbursement(): void
    {
        $c = $this->context();
        $this->post(route('finance.advances.limit', $c['staff']->id), ['limit' => '1200', 'reason' => 'Authorised cash exposure for this staff buyer before accountability.'])->assertRedirect()->assertSessionHasNoErrors();
        $data = ['staff_person_id' => $c['staff']->id, 'currency' => 'UGX', 'amount' => '1000', 'needed_on' => '2026-10-27', 'accountable_on' => '2026-10-28', 'reason' => 'Buy agreed market produce and return receipts and unused cash.', 'request_key' => (string) Str::uuid()];
        $this->post(route('finance.advances.request'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $request = AdvanceRequest::firstOrFail();
        $this->post(route('finance.advances.review', [$request->id, 'approve']), ['note' => 'Own request cannot approve its own cash authority.'])->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.advances.review', [$request->id, 'approve']), ['note' => 'Independently approved the purpose, amount and accountability date.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->money($c, $this->moneyData(['staff_person_id' => $c['staff']->id, 'advance_request_id' => $request->id, 'amount' => '600']));
        $this->actingAs($c['owner'])->post(route('finance.money.store'), $this->moneyData(['staff_person_id' => $c['staff']->id, 'advance_request_id' => $request->id, 'amount' => '401']))->assertStatus(422);
        $this->post(route('finance.money.store'), $this->moneyData(['staff_person_id' => $c['staff']->id, 'amount' => '601']))->assertStatus(422);
        $this->money($c, $this->moneyData(['staff_person_id' => $c['staff']->id, 'advance_request_id' => $request->id, 'amount' => '400']));
        $this->assertDatabaseCount('finance_operations', 2);
    }

    public function test_personal_market_purchase_clears_vendor_debt_without_repeating_the_produce_cost_and_trip_can_close(): void
    {
        $c = $this->context();
        $trip = MarketTrip::create(['business_id' => $c['business']->id, 'reference' => 'TRIP-001', 'staff_person_id' => $c['staff']->id, 'location' => 'Kampala market', 'due_at' => now(), 'budget' => '1000', 'currency' => 'UGX', 'status' => 'closed', 'notes' => 'Operational buying work is finished; financial accountability is pending.', 'request_key' => (string) Str::uuid(), 'created_by' => $c['owner']->id]);
        $purchase = PurchaseOrder::create(['business_id' => $c['business']->id, 'reference' => 'MARKET-PO', 'kind' => 'market', 'market_trip_id' => $trip->id, 'product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '100', 'estimated_total' => '1000', 'currency' => 'UGX', 'status' => 'received', 'purchased_on' => '2026-10-27', 'created_by' => $c['owner']->id, 'idempotency_key' => (string) Str::uuid()]);
        $batch = StockBatch::create(['business_id' => $c['business']->id, 'product_id' => $c['product']->id, 'batch_code' => 'ACTUAL-MARKET-BATCH', 'received_on' => '2026-10-27']);
        StockMovement::create(['business_id' => $c['business']->id, 'batch_id' => $batch->id, 'movement_type' => 'received', 'quantity' => '10', 'unit' => 'kg', 'source_type' => 'fixture_receipt', 'source_id' => 1, 'created_by' => $c['owner']->id]);
        GoodsReceipt::create(['business_id' => $c['business']->id, 'purchase_order_id' => $purchase->id, 'batch_id' => $batch->id, 'reference' => 'MARKET-RECEIPT', 'received_quantity' => '10', 'accepted_quantity' => '10', 'rejected_quantity' => '0', 'received_on' => '2026-10-27', 'posted_by' => $c['owner']->id, 'idempotency_key' => (string) Str::uuid()]);
        $bill = app(FinanceDocuments::class)->supplierBill($c['business'], $purchase, ['vendor_invoice_reference' => 'MARKET-VENDOR-RECEIPT', 'vendor_invoice_date' => '2026-10-27'], $c['owner']->id);
        $this->postSource($c, 'supplier_bill', $bill);
        $claim = $this->money($c, $this->moneyData(['kind' => 'market_reimbursement', 'staff_person_id' => $c['staff']->id, 'market_trip_id' => $trip->id, 'supplier_bill_id' => $bill->id, 'amount' => '1000']));
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($bill, false)->isZero());
        $this->assertTrue(app(FinanceOperations::class)->remaining($claim, false)->isEqualTo('1000'));
        $this->actingAs($c['reviewer'])->post(route('finance.market.close', $trip->id), ['reason' => 'Accepted produce, posted vendor receipt and personal staff claim are reconciled.'])->assertRedirect()->assertSessionHasNoErrors();
        $closure = MarketAccountability::firstOrFail();
        $this->assertSame('1000.00', $closure->data_snapshot['staff_reimbursements'][0]['unpaid']);
        $this->actingAs($c['owner'])->post(route('finance.money.store'), $this->moneyData(['kind' => 'staff_advance', 'staff_person_id' => $c['staff']->id, 'market_trip_id' => $trip->id, 'amount' => '100']))->assertStatus(422);
        $this->actingAs($c['reviewer'])->post(route('finance.market.reopen', $closure->id), ['reason' => 'A late market receipt needs a controlled accountability correction.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reopened', $closure->fresh()->status);
        $this->get(route('finance.trade.index'))->assertOk()->assertSee('Financial closure history');
    }

    public function test_due_reminders_are_internal_idempotent_and_disputes_do_not_forgive_debt(): void
    {
        $c = $this->context();
        $quote = $this->quote($c);
        $sales = app(SalesWorkflow::class);
        $sales->issue($quote, $c['owner']->id);
        $order = $sales->confirm($quote, 'Buyer confirmed the issued offer.', $c['owner']->id);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'sales_order_id' => $order->id, 'customer_id' => $c['customer']->id, 'reference' => 'SHIP-DUE', 'status' => 'dispatched', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $c['owner']->id]);
        $invoice = CommercialInvoice::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id, 'customer_id' => $c['customer']->id, 'reference' => 'INVOICE-DUE', 'currency' => 'UGX', 'subtotal' => '1000', 'discount_amount' => '0', 'tax_amount' => '0', 'freight_amount' => '0', 'insurance_amount' => '0', 'grand_total' => '1000', 'due_on' => '2026-10-27', 'posting_status' => 'posted', 'data_snapshot' => [], 'html_snapshot' => '<h1>Due invoice</h1>', 'sha256' => hash('sha256', 'due invoice'), 'issued_by' => $c['owner']->id, 'issued_at' => now()]);
        $this->artisan('expo:trade-reminders', ['--business' => $c['business']->id])->assertExitCode(0);
        $this->artisan('expo:trade-reminders', ['--business' => $c['business']->id])->assertExitCode(0);
        $this->assertDatabaseCount('trade_collection_cases', 1);
        $case = TradeCollectionCase::firstOrFail();
        $this->post(route('finance.trade.followup', ['customer', $invoice->id]), ['staff_person_id' => $c['staff']->id, 'status' => 'disputed', 'disputed_amount' => '200', 'next_action_on' => '2026-10-28', 'note' => 'Buyer queries two cartons; responsible staff will provide their signed receipt.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice, false)->isEqualTo('1000'));
        $this->post(route('finance.trade.followup', ['customer', $invoice->id]), ['status' => 'resolved', 'disputed_amount' => '0', 'note' => 'The balance is still unpaid and cannot be marked financially cleared.'])->assertStatus(422);
        $this->assertSame('disputed', $case->fresh()->status);
    }

    public function test_customer_credit_exception_requires_a_different_invoice_issuer(): void
    {
        $c = $this->context();
        app(FinanceTradeControls::class)->saveTerms($c['business'], ['customer_id' => $c['customer']->id, 'currency' => 'UGX', 'payment_days' => 7, 'credit_limit' => '500', 'effective_on' => '2026-10-27', 'agreement' => 'Agreed maximum unpaid invoice exposure for this overseas customer.'], $c['owner']->id);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $c['customer']->id, 'reference' => 'CREDIT-SHIP', 'status' => 'ready', 'transport_mode' => 'air', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $c['owner']->id]);
        $this->actingAs($c['reviewer'])->post(route('finance.trade.credit-exception', $shipment->id), ['currency' => 'UGX', 'exposure_limit' => '1000', 'expires_on' => '2026-10-28', 'reason' => 'Owner accepts this specific shipment exposure against the buyer promise.'])->assertRedirect()->assertSessionHasNoErrors();
        $credit = app(FinanceTradeControls::class)->credit($c['business'], $c['customer'], 'UGX', '1000', $shipment->id, $c['owner']->id);
        $this->assertNotNull($credit['exception_id']);
        $this->expectException(HttpException::class);
        app(FinanceTradeControls::class)->credit($c['business'], $c['customer'], 'UGX', '1000', $shipment->id, $c['reviewer']->id);
    }
}
