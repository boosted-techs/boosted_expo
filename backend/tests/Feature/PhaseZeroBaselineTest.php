<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinancePayment;
use App\Models\FinanceSupplierBill;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesRequest;
use App\Models\Supplier;
use App\Models\SupplierRequestLink;
use App\Models\SupplyRequest;
use App\Models\User;
use App\Services\FinanceSetup;
use App\Services\SupplierQuoting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PhaseZeroBaselineTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code = 'BASELINE', string $role = 'owner'): array
    {
        $this->freezeTime();
        $business = Business::create([
            'name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala',
            'default_qr_level' => 'batch', 'enabled_currencies' => ['UGX', 'USD'],
        ]);
        $user = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'sku' => 'AVO', 'name' => 'Avocado', 'base_unit' => 'kg']);

        return compact('business', 'user', 'product');
    }

    private function signIn(array $context): self
    {
        return $this->actingAs($context['user'])->withSession(['active_business_id' => $context['business']->id]);
    }

    private function purchase(array $context, string $reference): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $context['business']->id, 'reference' => $reference, 'kind' => 'market',
            'product_id' => $context['product']->id, 'vendor_name' => 'Market vendor',
            'purchased_on' => now()->toDateString(), 'quantity' => '10', 'unit_price' => '10',
            'estimated_total' => '100', 'currency' => 'UGX', 'status' => 'received',
            'idempotency_key' => (string) Str::uuid(), 'created_by' => $context['user']->id,
        ]);
    }

    private function bill(array $context, string $reference, string $total = '100', string $status = 'posted'): FinanceSupplierBill
    {
        $purchase = $this->purchase($context, 'PO-'.$reference);

        return FinanceSupplierBill::create([
            'business_id' => $context['business']->id, 'purchase_order_id' => $purchase->id,
            'reference' => $reference, 'vendor_invoice_reference' => 'VENDOR-'.$reference,
            'vendor_invoice_date' => now()->toDateString(), 'accepted_quantity' => '10',
            'total' => $total, 'currency' => 'UGX', 'posting_status' => $status, 'created_by' => $context['user']->id,
        ]);
    }

    private function payment(array $context, ?FinanceSupplierBill $bill, string $amount, string $status = 'posted'): FinancePayment
    {
        app(FinanceSetup::class)->activate($context['business']);
        $account = app(FinanceSetup::class)->account($context['business']->id, 'bank');

        return FinancePayment::create([
            'business_id' => $context['business']->id, 'reference' => 'PAY-'.Str::ulid(),
            'direction' => $bill ? 'out' : 'in', 'supplier_bill_id' => $bill?->id,
            'cash_account_id' => $account->id, 'currency' => 'UGX', 'amount' => $amount,
            'payment_on' => now()->toDateString(), 'external_reference' => 'BANK-'.Str::ulid(),
            'status' => $status, 'idempotency_key' => (string) Str::uuid(), 'created_by' => $context['user']->id,
        ]);
    }

    private function supplierLink(array $context): array
    {
        $supplier = Supplier::create(['business_id' => $context['business']->id, 'supplier_code' => 'FARM', 'name' => 'Test farm', 'kind' => 'company', 'active' => true]);
        $rfq = SupplyRequest::create([
            'business_id' => $context['business']->id, 'reference' => 'RFQ-BASELINE',
            'product_id' => $context['product']->id, 'quantity' => '100', 'currency' => 'UGX',
            'status' => 'sent', 'response_deadline' => now()->addDay(), 'created_by' => $context['user']->id,
        ]);
        $token = Str::random(64);
        SupplierRequestLink::create([
            'business_id' => $context['business']->id, 'supply_request_id' => $rfq->id,
            'supplier_id' => $supplier->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);

        return compact('supplier', 'rfq', 'token');
    }

    private function invoice(array $context, Customer $customer, string $reference): CommercialInvoice
    {
        $businessId = $context['business']->id;
        $actor = $context['user']->id;
        $request = SalesRequest::create([
            'business_id' => $businessId, 'customer_id' => $customer->id,
            'reference' => 'RFQ-'.$reference, 'status' => 'confirmed', 'created_by' => $actor,
        ]);
        $quotation = SalesQuotation::create([
            'business_id' => $businessId, 'sales_request_id' => $request->id, 'reference' => 'Q-'.$reference,
            'revision' => 1, 'status' => 'accepted', 'currency' => 'UGX', 'subtotal' => '100',
            'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '100', 'created_by' => $actor,
        ]);
        $order = SalesOrder::create([
            'business_id' => $businessId, 'sales_request_id' => $request->id, 'sales_quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'reference' => 'SO-'.$reference, 'currency' => 'UGX',
            'subtotal' => '100', 'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '100',
            'buyer_confirmation' => 'Buyer accepted quotation', 'confirmed_by' => $actor, 'confirmed_at' => now(),
        ]);
        $shipment = ExportShipment::create([
            'business_id' => $businessId, 'customer_id' => $customer->id, 'sales_order_id' => $order->id,
            'reference' => 'SHP-'.$reference, 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $actor,
        ]);

        return CommercialInvoice::create([
            'business_id' => $businessId, 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id,
            'customer_id' => $customer->id, 'reference' => $reference, 'currency' => 'UGX',
            'subtotal' => '100', 'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '100',
            'freight_amount' => '0', 'insurance_amount' => '0', 'posting_status' => 'posted',
            'data_snapshot' => [], 'html_snapshot' => '<h1>Invoice</h1>', 'sha256' => hash('sha256', $reference),
            'issued_by' => $actor, 'issued_at' => now(),
        ]);
    }

    public function test_disabled_business_supplier_page_returns_not_found(): void
    {
        $context = $this->company();
        $link = $this->supplierLink($context);
        $context['business']->update(['is_active' => false]);

        $this->get(route('supplier-offer.show', $link['token']))->assertNotFound();
    }

    public function test_disabled_business_supplier_submission_creates_no_quote_or_revision(): void
    {
        $context = $this->company();
        $link = $this->supplierLink($context);
        $context['business']->update(['is_active' => false]);

        $this->post(route('supplier-offer.store', $link['token']), ['quantity' => '10', 'unit_price' => '1000'])->assertNotFound();

        $this->assertDatabaseCount('supplier_quotes', 0);
        $this->assertDatabaseCount('supplier_quote_revisions', 0);
    }

    #[TestWith(['business'])]
    #[TestWith(['supplier'])]
    public function test_quote_service_rechecks_state_instead_of_trusting_old_models(string $disabled): void
    {
        $context = $this->company();
        $link = $this->supplierLink($context);
        if ($disabled === 'business') {
            Business::query()->whereKey($context['business']->id)->update(['is_active' => false]);
        } else {
            Supplier::query()->whereKey($link['supplier']->id)->update(['active' => false]);
        }

        try {
            app(SupplierQuoting::class)->record($link['rfq'], $link['supplier'], ['quantity' => '10', 'unit_price' => '1000'], 'supplier_link');
            $this->fail('Disabled state must be rechecked before accepting a quote.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('supplier_quotes', 0);
        $this->assertDatabaseCount('supplier_quote_revisions', 0);
    }

    public function test_reinstating_business_allows_an_unexpired_supplier_quote(): void
    {
        $context = $this->company();
        $link = $this->supplierLink($context);
        $context['business']->update(['is_active' => false]);
        $context['business']->update(['is_active' => true]);

        $this->post(route('supplier-offer.store', $link['token']), ['quantity' => '10', 'unit_price' => '1000'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('supplier_quotes', ['business_id' => $context['business']->id, 'quantity' => '10']);
        $this->assertDatabaseCount('supplier_quote_revisions', 1);
    }

    public function test_owner_configures_only_their_business_currencies(): void
    {
        $context = $this->company();
        $other = $this->company('OTHER');

        $this->signIn($context)->put(route('business.currencies.update'), ['enabled_currencies' => ['UGX', 'KES']])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['UGX', 'KES'], $context['business']->fresh()->enabled_currencies);
        $this->assertSame(['UGX', 'USD'], $other['business']->fresh()->enabled_currencies);
        $this->get(route('business.settings'))->assertOk()->assertSee('Trading currencies')->assertSee('Save trading currencies');
    }

    #[TestWith(['admin'])]
    #[TestWith(['accountant'])]
    #[TestWith(['buyer'])]
    #[TestWith(['sales'])]
    #[TestWith(['warehouse'])]
    #[TestWith(['packing'])]
    #[TestWith(['logistics'])]
    #[TestWith(['hr'])]
    #[TestWith(['viewer'])]
    public function test_non_owner_cannot_change_business_currencies(string $role): void
    {
        $context = $this->company(role: $role);

        $this->signIn($context)->put(route('business.currencies.update'), ['enabled_currencies' => ['UGX', 'KES']])->assertForbidden();

        $this->assertSame(['UGX', 'USD'], $context['business']->fresh()->enabled_currencies);
    }

    #[TestWith([['USD']])]
    #[TestWith([['UGX', 'ZZZ']])]
    #[TestWith([['UGX', 'USD', 'USD']])]
    public function test_currency_settings_reject_missing_base_unknown_or_duplicate_codes(array $currencies): void
    {
        $context = $this->company();

        $this->signIn($context)->put(route('business.currencies.update'), ['enabled_currencies' => $currencies])->assertSessionHasErrors();

        $this->assertSame(['UGX', 'USD'], $context['business']->fresh()->enabled_currencies);
    }

    #[TestWith(['ZZZ'])]
    #[TestWith(['KES'])]
    public function test_unknown_or_disabled_currency_cannot_create_a_purchase(string $currency): void
    {
        $context = $this->company();

        $this->signIn($context)->post(route('purchases.store'), [
            'kind' => 'market', 'product_id' => $context['product']->id, 'purchased_on' => now()->toDateString(),
            'quantity' => '10', 'unit_price' => '1000', 'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('currency');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_allowed_currency_is_normalized_and_offered_in_purchase_form(): void
    {
        $context = $this->company();

        $this->signIn($context)->post(route('purchases.store'), [
            'kind' => 'market', 'product_id' => $context['product']->id, 'purchased_on' => now()->toDateString(),
            'quantity' => '10', 'unit_price' => '1000', 'currency' => ' usd ', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('purchase_orders', ['business_id' => $context['business']->id, 'currency' => 'USD']);
        $this->get(route('purchases.create'))->assertOk()->assertSee('value="USD"', false)->assertDontSee('value="KES"', false);
    }

    public function test_old_unpaid_bill_survives_newer_paid_bills_and_can_be_selected_for_payment(): void
    {
        $context = $this->company();
        $old = $this->bill($context, 'OLD-UNPAID');
        for ($i = 0; $i < 76; $i++) {
            $this->payment($context, $this->bill($context, 'NEW-PAID-'.$i), '100');
        }
        $other = $this->company('OTHER');
        $this->bill($other, 'OTHER-UNPAID');

        $this->signIn($context)->get(route('finance.documents'))->assertOk()
            ->assertViewHas('payableBills', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)
            ->assertSee('OLD-UNPAID')->assertDontSee('OTHER-UNPAID');
        $this->post(route('finance.payments.store'), [
            'direction' => 'out', 'supplier_bill_id' => $old->id, 'cash_account_key' => 'cash',
            'amount' => '20', 'payment_on' => now()->toDateString(), 'external_reference' => 'OLD-BILL-PAYMENT',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('finance_payments', ['supplier_bill_id' => $old->id, 'amount' => '20', 'status' => 'pending']);
    }

    public function test_outstanding_filter_handles_pending_payments_decimal_rounding_and_concessions(): void
    {
        $context = $this->company();
        $paid = $this->bill($context, 'SMALL-PAID', '0.30');
        $this->payment($context, $paid, '0.10');
        $this->payment($context, $paid, '0.20', 'pending');
        $partial = $this->bill($context, 'PARTIAL');
        $this->payment($context, $partial, '20');
        $this->payment($context, $partial, '30', 'pending');
        $concession = $this->bill($context, 'CONCESSION');
        $this->payment($context, $concession, '70');
        FinanceBalanceAdjustment::create([
            'business_id' => $context['business']->id, 'reference' => 'ADJ-TEST', 'kind' => 'supplier',
            'supplier_bill_id' => $concession->id, 'currency' => 'UGX', 'amount' => '30', 'reason' => 'Approved concession',
            'data_snapshot' => [], 'sha256' => hash('sha256', 'concession'), 'posting_status' => 'posted', 'created_by' => $context['user']->id,
        ]);

        $this->signIn($context)->get(route('finance.documents'))->assertOk()
            ->assertViewHas('payableBills', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $partial->id)
            ->assertViewHas('billBalances', fn ($balances) => $balances[$partial->id] === '50');
    }

    public function test_unbilled_purchase_is_filtered_before_pagination(): void
    {
        $context = $this->company();
        $old = $this->purchase($context, 'OLD-UNBILLED');
        for ($i = 0; $i < 76; $i++) {
            $this->bill($context, 'BILLED-'.$i);
        }

        $this->signIn($context)->get(route('finance.documents'))->assertOk()
            ->assertViewHas('purchases', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)->assertSee('OLD-UNBILLED');
    }

    public function test_old_customer_invoice_remains_selectable_after_many_newer_paid_invoices(): void
    {
        $context = $this->company();
        $customer = Customer::create([
            'business_id' => $context['business']->id, 'customer_code' => 'BUYER', 'name' => 'Export buyer',
            'country_code' => 'AE', 'currency' => 'UGX',
        ]);
        $old = $this->invoice($context, $customer, 'OLD-RECEIVABLE');
        for ($i = 0; $i < 76; $i++) {
            $invoice = $this->invoice($context, $customer, 'PAID-'.$i);
            $this->payment($context, null, '100')->update(['commercial_invoice_id' => $invoice->id]);
        }
        $this->payment($context, null, '25', 'pending')->update(['commercial_invoice_id' => $old->id]);

        $this->signIn($context)->get(route('finance.documents', ['invoice_search' => 'OLD']))->assertOk()
            ->assertViewHas('invoices', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)
            ->assertViewHas('invoiceBalances', fn ($balances) => $balances[$old->id] === '75');
        $this->get(route('finance.documents'))->assertOk()
            ->assertViewHas('invoices', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id);
    }

    public function test_outstanding_bill_search_and_pagination_preserve_business_scope(): void
    {
        $context = $this->company();
        $old = $this->bill($context, 'FIND-OLD');
        for ($i = 0; $i < 76; $i++) {
            $this->bill($context, 'RECENT-'.$i);
        }
        $other = $this->company('OTHER');
        $this->bill($other, 'FIND-OTHER');

        $this->signIn($context)->get(route('finance.documents', ['bill_search' => 'FIND']))->assertOk()
            ->assertViewHas('payableBills', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)->assertSee('FIND-OLD')->assertDontSee('FIND-OTHER');
        $this->get(route('finance.documents', ['payable_page' => 6]))->assertOk()->assertSee('FIND-OLD');
    }

    public function test_posting_source_search_finds_records_older_than_the_old_hundred_record_cutoff(): void
    {
        $context = $this->company();
        $old = $this->bill($context, 'FIND-OLD', status: 'not_posted');
        for ($i = 0; $i < 101; $i++) {
            $this->bill($context, 'RECENT-'.$i, status: 'not_posted');
        }

        $this->signIn($context)->get(route('finance.reviews', ['source_search' => 'FIND-OLD']))->assertOk()
            ->assertViewHas('bills', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)->assertSee('FIND-OLD');
    }

    public function test_old_amount_matching_bank_payment_remains_available_after_newer_records(): void
    {
        $context = $this->company();
        $old = $this->payment($context, null, '60');
        for ($i = 0; $i < 251; $i++) {
            $this->payment($context, null, '20');
        }
        $statement = FinanceBankStatement::create([
            'business_id' => $context['business']->id, 'account_id' => $old->cash_account_id,
            'reference' => 'STATEMENT', 'currency' => 'UGX', 'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(),
            'opening_balance' => '0', 'closing_balance' => '60', 'credits' => '60', 'debits' => '0',
            'file_sha256' => hash('sha256', 'statement'), 'filename' => 'bank.csv', 'status' => 'open', 'imported_by' => $context['user']->id,
        ]);
        FinanceBankStatementLine::create([
            'business_id' => $context['business']->id, 'statement_id' => $statement->id, 'line_number' => 1,
            'transaction_on' => now()->toDateString(), 'direction' => 'credit', 'amount' => '60',
            'bank_reference' => $old->external_reference, 'description' => 'Old payment',
        ]);

        $this->signIn($context)->get(route('finance.bank.show', $statement->id))->assertOk()
            ->assertViewHas('payments', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id)->assertSee($old->reference);
    }

    public function test_disabled_currency_customer_can_be_updated_without_rewriting_its_history(): void
    {
        $context = $this->company();
        $customer = Customer::create([
            'business_id' => $context['business']->id, 'customer_code' => 'LEGACY', 'name' => 'Existing Buyer',
            'country_code' => 'AE', 'currency' => 'USD',
        ]);
        $context['business']->update(['enabled_currencies' => ['UGX']]);

        $this->signIn($context)->put(route('customers.update', $customer->id), [
            'customer_code' => 'LEGACY', 'name' => 'Existing Buyer Updated', 'country_code' => 'AE', 'currency' => 'USD', 'active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('USD', $customer->fresh()->currency);
        $this->get(route('customers.edit', $customer->id))->assertOk()->assertSee('value="USD"', false);
    }
}
