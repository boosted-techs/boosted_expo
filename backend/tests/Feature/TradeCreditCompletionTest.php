<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTradeCredit;
use App\Models\FinanceWorkflow;
use App\Models\GoodsReceipt;
use App\Models\PackingJob;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesRequest;
use App\Models\ShipmentCarton;
use App\Models\StockBatch;
use App\Models\StockExceptionCase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceMonetaryRevaluations;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use App\Services\FinanceTradeCredits;
use App\Services\FinanceWorkflows;
use App\Services\PackingOperations;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradeCreditCompletionTest extends TestCase
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

    private function postSource(array $c, string $type, int $id, ?int $fx = null): void
    {
        $this->actingAs($c['accountant'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.reviews.request', ['type' => $type, 'source' => $id]), ['posting_on' => '2026-10-27', 'exchange_rate_id' => $fx, 'basis_notes' => 'Verified quantity, source basis and independent business evidence.'])->assertRedirect()->assertSessionHasNoErrors();
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
        return [...['event_on' => '2026-10-27', 'reason' => 'Signed agreed credit and verified original invoice and case evidence.', 'external_reference' => 'CREDIT-001', 'request_key' => (string) Str::uuid(), 'tax_amount' => '0'], ...$extra];
    }

    private function invoice(array $c, ?Customer $customer = null, string $currency = 'UGX', string $total = '1000', ?int $fx = null): array
    {
        $customer ??= Customer::create(['business_id' => $c['business']->id, 'customer_code' => 'BUY-'.Str::ulid(), 'name' => 'Claim importer', 'country_code' => 'AE', 'currency' => $currency, 'active' => true]);
        $request = SalesRequest::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'reference' => 'REQ-'.Str::ulid(), 'status' => 'confirmed', 'created_by' => $c['owner']->id]);
        $order = SalesOrder::create(['business_id' => $c['business']->id, 'reference' => 'SO-'.Str::ulid(), 'buyer_confirmation' => 'Verified original buyer instruction', 'sales_request_id' => $request->id, 'customer_id' => $customer->id, 'status' => 'confirmed', 'pricing_status' => 'accepted', 'currency' => $currency, 'subtotal' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $total, 'confirmed_by' => $c['owner']->id, 'confirmed_at' => now()]);
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'customer_id' => $customer->id, 'sales_order_id' => $order->id, 'reference' => 'SHIP-'.Str::ulid(), 'transport_mode' => 'air', 'status' => 'dispatched', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $c['owner']->id]);
        $invoice = CommercialInvoice::create(['business_id' => $c['business']->id, 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id, 'customer_id' => $customer->id, 'reference' => 'CI-'.Str::ulid(), 'currency' => $currency, 'subtotal' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'freight_amount' => 0, 'insurance_amount' => 0, 'grand_total' => $total, 'posting_status' => 'not_posted', 'issued_by' => $c['owner']->id, 'issued_at' => now(), 'data_snapshot' => [], 'html_snapshot' => 'Invoice', 'sha256' => hash('sha256', 'Invoice'), 'due_on' => '2026-11-01']);
        $this->postSource($c, 'commercial_invoice', $invoice->id, $fx);

        return compact('invoice', 'shipment', 'customer');
    }

    private function claim(array $c, array $sale): StockExceptionCase
    {
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'PACK-'.Str::ulid(), 'product_id' => $c['product']->id, 'status' => 'open', 'created_by' => $c['warehouse']->id]);
        $carton = app(PackingOperations::class)->packCarton($job, ['packing_key' => (string) Str::uuid(), 'net_weight_kg' => '5', 'gross_weight_kg' => '6', 'contents' => [['batch_id' => $c['batch']->id, 'quantity' => '5']]], $c['warehouse']->id);
        ShipmentCarton::create(['business_id' => $c['business']->id, 'shipment_id' => $sale['shipment']->id, 'carton_id' => $carton->id, 'allocated_by' => $c['owner']->id]);
        $carton->update(['status' => 'shipped']);
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('stock.cases.store'), ['kind' => 'quality_claim', 'batch_id' => $c['batch']->id, 'event_on' => '2026-10-27', 'reason' => 'Buyer quality complaint with independent original shipment evidence.', 'external_reference' => 'QUALITY-001', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return StockExceptionCase::latest('id')->firstOrFail();
    }

    private function prepareCredit(array $c, array $data): FinanceTradeCredit
    {
        $this->actingAs($c['accountant'])->withSession(['active_business_id' => $c['business']->id])->post(route('finance.trade-credits.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceTradeCredit::latest('id')->firstOrFail();
    }

    private function balance(array $c, string $key): string
    {
        return (string) app(FinanceWorkflows::class)->accountBasis($c['business']->id, app(FinanceSetup::class)->account($c['business']->id, $key)->id, '2026-10-27')['base']->toScale(2);
    }

    public function test_credit_after_customer_payment_clears_remaining_debt_and_tracks_refundable_excess(): void
    {
        $c = $this->context();
        $sale = $this->invoice($c);
        $case = $this->claim($c, $sale);
        $payment = app(FinanceDocuments::class)->payment($c['business'], ['direction' => 'in', 'commercial_invoice_id' => $sale['invoice']->id, 'amount' => '800', 'payment_on' => '2026-10-27', 'cash_account_key' => 'cash', 'external_reference' => 'ACTUAL-RECEIPT', 'idempotency_key' => (string) Str::uuid()], $c['accountant']->id);
        $this->postSource($c, 'payment', $payment->id);
        $credit = $this->prepareCredit($c, $this->data(['document_type' => 'customer', 'document_id' => $sale['invoice']->id, 'case_id' => $case->id, 'amount' => '400', 'due_on' => '2026-11-01']));
        $this->assertTrue(BigDecimal::of((string) $credit->applied_amount)->isEqualTo('200'));
        $this->assertTrue(BigDecimal::of((string) $credit->excess_amount)->isEqualTo('200'));
        $this->postSource($c, 'finance_workflow', $credit->workflow_id);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($sale['invoice']->fresh())->isZero());
        $this->assertSame('0.00', $this->balance($c, 'receivable'));
        $this->assertSame('-200.00', $this->balance($c, 'customer_credit'));
        $this->assertSame('400.00', $this->balance($c, 'sales_returns'));
        $this->get(route('finance.trade-credits.index'))->assertOk()->assertSee($credit->workflow->reference);
        $data = $this->data(['amount' => '50', 'account_key' => 'cash']);
        unset($data['tax_amount']);
        $this->actingAs($c['accountant'])->post(route('finance.trade-credits.use', [$credit->id, 'trade_credit_settlement']), $data)->assertRedirect()->assertSessionHasNoErrors();
        $flow = FinanceWorkflow::where('request_key', $data['request_key'])->firstOrFail();
        $this->postSource($c, 'finance_workflow', $flow->id);
        $this->assertSame('750.00', $this->balance($c, 'cash'));
        $this->assertTrue(app(FinanceTradeCredits::class)->remaining($credit->fresh())['amount']->isEqualTo('150'));
        $target = $this->invoice($c, $sale['customer']);
        $data = $this->data(['amount' => '150', 'document_id' => $target['invoice']->id]);
        unset($data['tax_amount']);
        $this->actingAs($c['accountant'])->post(route('finance.trade-credits.use', [$credit->id, 'trade_credit_apply']), $data)->assertRedirect()->assertSessionHasNoErrors();
        $flow = FinanceWorkflow::where('request_key', $data['request_key'])->firstOrFail();
        $this->postSource($c, 'finance_workflow', $flow->id);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($target['invoice']->fresh())->isEqualTo('850'));
        $this->assertSame('0.00', $this->balance($c, 'customer_credit'));
    }

    public function test_supplier_return_credit_resolves_stock_claim_and_reduces_only_agreed_debt(): void
    {
        $c = $this->context();
        $this->receiptCost($c);
        $bill = FinanceSupplierBill::firstOrFail();
        $this->actingAs($c['warehouse'])->withSession(['active_business_id' => $c['business']->id])->post(route('stock.cases.store'), ['kind' => 'supplier_return', 'batch_id' => $c['batch']->id, 'quantity' => '2', 'event_on' => '2026-10-27', 'reason' => 'Two kilograms physically returned with signed supplier custody evidence.', 'external_reference' => 'SUP-RETURN', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $case = StockExceptionCase::firstOrFail();
        $this->actingAs($c['owner'])->post(route('stock.cases.review', [$case->id, 'approve']), ['note' => 'Verified actual returned weight and original source batch.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postSource($c, 'finance_workflow', $case->fresh()->workflow_id);
        $credit = $this->prepareCredit($c, $this->data(['document_type' => 'supplier', 'document_id' => $bill->id, 'case_id' => $case->id, 'amount' => '180']));
        $this->postSource($c, 'finance_workflow', $credit->workflow_id);
        $this->assertSame('0.00', $this->balance($c, 'supplier_return_pending'));
        $this->assertSame('20.00', $this->balance($c, 'supplier_return_loss'));
        $this->assertSame('-820.00', $this->balance($c, 'payable'));
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($bill)->isEqualTo('820'));
        $this->assertTrue(app(FinanceCostBasis::class)->batchBasis($c['batch'])['cost']->isEqualTo('800'));
    }

    public function test_foreign_credit_consumes_revalued_document_basis_and_releases_only_its_unrealised_share(): void
    {
        $c = $this->context();
        $rates = [];
        foreach (['3700', '3800'] as $value) {
            $rate = app(FinanceRates::class)->propose($c['business'], ['currency' => 'USD', 'rate_to_base' => $value, 'effective_on' => '2026-10-27', 'source_note' => 'Verified manual commercial currency evidence.'], $c['accountant']->id);
            app(FinanceRates::class)->approve($rate, $c['owner']->id);
            $rates[] = $rate;
        }$sale = $this->invoice($c, null, 'USD', '100', $rates[0]->id);
        $case = $this->claim($c, $sale);
        $revalue = app(FinanceMonetaryRevaluations::class)->prepare($c['business'], ['source_type' => 'commercial_invoice', 'source_id' => $sale['invoice']->id, 'valued_on' => '2026-10-27', 'exchange_rate_id' => $rates[1]->id, 'valuation_evidence' => 'Verified manual reporting rate and outstanding foreign balance.'], $c['accountant']->id);
        $this->postSource($c, 'monetary_revaluation', $revalue->id, $rates[1]->id);
        $credit = $this->prepareCredit($c, $this->data(['document_type' => 'customer', 'document_id' => $sale['invoice']->id, 'case_id' => $case->id, 'amount' => '50']));
        $this->postSource($c, 'finance_workflow', $credit->workflow_id);
        $position = app(FinanceMonetaryRevaluations::class)->position($c['business'], 'commercial_invoice', $sale['invoice']->id);
        $this->assertSame('50.00', $position['foreign_balance']);
        $this->assertSame('190000.00', $position['previous_base']);
        $this->assertSame('5000.00', $position['unrealized_open']);
        $this->assertSame('185000.00', $this->balance($c, 'sales_returns'));
    }
}
