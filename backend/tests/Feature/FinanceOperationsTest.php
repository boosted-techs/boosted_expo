<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceSupplierBill;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesRequest;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\FinanceBankProfiles;
use App\Services\FinanceCostBasis;
use App\Services\FinanceDocuments;
use App\Services\FinanceMath;
use App\Services\FinanceOperations;
use App\Services\FinanceRates;
use App\Services\FinanceSetup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function company(string $code = 'MNY'): array
    {
        $business = Business::create(['name' => 'Money Exporters', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'carton']);
        $preparer = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$preparer, $reviewer] as $user) {
            BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        }
        app(FinanceSetup::class)->activate($business);
        $person = StaffPerson::create(['business_id' => $business->id, 'staff_code' => 'CAS-01', 'name' => 'Market worker', 'kind' => 'casual', 'active' => true]);

        return compact('business', 'preparer', 'reviewer', 'person');
    }

    private function data(array $extra = []): array
    {
        return [...['kind' => 'staff_advance', 'currency' => 'UGX', 'amount' => '1000.00', 'event_on' => now()->toDateString(), 'description' => 'Market accountability voucher', 'external_reference' => 'VOUCHER-001', 'idempotency_key' => (string) Str::uuid(), 'cash_account_key' => 'cash'], ...$extra];
    }

    private function create(array $ctx, array $data): FinanceOperation
    {
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return FinanceOperation::query()->where('business_id', $ctx['business']->id)->where('idempotency_key', $data['idempotency_key'])->firstOrFail();
    }

    private function postOperation(array $ctx, FinanceOperation|FinanceSupplierBill|CommercialInvoice $operation, string $type = 'money_operation', ?int $rateId = null): FinanceJournal
    {
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.reviews.request', ['type' => $type, 'source' => $operation->id]), [
            'posting_on' => ($operation->event_on ?? $operation->vendor_invoice_date ?? $operation->issued_at)->toDateString(), 'exchange_rate_id' => $rateId ?? $operation->exchange_rate_id, 'basis_notes' => 'Verified voucher, event date, parties and actual money account.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $review = FinancePostingRequest::query()->where('source_type', $type)->where('source_id', $operation->id)->firstOrFail();
        $this->post(route('finance.reviews.approve', $review->id), ['notes' => 'Checking own preparation must fail.'])->assertStatus(422);
        $this->actingAs($ctx['reviewer'])->post(route('finance.reviews.approve', $review->id), ['notes' => 'Checked supporting voucher independently.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.reviews.post', $review->id))->assertStatus(422);
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.reviews.post', $review->id))->assertRedirect()->assertSessionHasNoErrors();

        return FinanceJournal::query()->where('source_type', $type)->where('source_id', $operation->id)->firstOrFail();
    }

    public function test_staff_advance_is_accounted_for_once_by_expense_and_unused_cash_return(): void
    {
        $this->freezeTime();
        $ctx = $this->company();
        $advance = $this->create($ctx, $this->data(['staff_person_id' => $ctx['person']->id]));
        $this->postOperation($ctx, $advance);
        $expense = $this->create($ctx, $this->data(['kind' => 'staff_expense', 'parent_id' => $advance->id, 'amount' => '300', 'category' => 'transport']));
        $this->postOperation($ctx, $expense);
        $return = $this->create($ctx, $this->data(['kind' => 'staff_return', 'parent_id' => $advance->id, 'amount' => '700']));
        $this->postOperation($ctx, $return);
        $this->assertSame('0.00', (string) app(FinanceOperations::class)->remaining($advance));
        $balances = [];
        foreach (FinanceJournal::query()->with('lines.account')->get() as $journal) {
            foreach ($journal->lines as $line) {
                $balances[$line->account->system_key] = ($balances[$line->account->system_key] ?? 0) + (int) $line->base_debit - (int) $line->base_credit;
            }
        }
        $this->assertSame(0, $balances['staff_advances']);
        $this->assertSame(-300, $balances['cash']);
        $this->assertSame(300, $balances['general_expense']);
        $this->actingAs($ctx['preparer'])->get(route('finance.money.index'))->assertOk()->assertSee('Market accountability voucher');
        $this->get(route('finance.money.show', $advance->id))->assertOk()->assertSee('0.00');
        $this->get(route('finance.money.create', ['kind' => 'staff_expense']))->assertOk();
        $this->get(route('finance.reviews'))->assertOk();
    }

    public function test_paid_expense_uses_separate_payment_and_pending_records_reserve_balance(): void
    {
        $ctx = $this->company();
        $expense = $this->create($ctx, $this->data(['kind' => 'expense', 'amount' => '800', 'category' => 'rent']));
        $journal = $this->postOperation($ctx, $expense);
        $this->assertFalse($journal->lines->contains(fn ($line) => $line->account->system_key === 'cash'));
        $paymentData = $this->data(['kind' => 'expense_payment', 'parent_id' => $expense->id, 'amount' => '500']);
        $payment = $this->create($ctx, $paymentData);
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), $paymentData)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, FinanceOperation::count());
        $this->post(route('finance.money.store'), [...$paymentData, 'amount' => '400'])->assertStatus(422);
        $this->post(route('finance.money.store'), $this->data(['kind' => 'expense_payment', 'parent_id' => $expense->id, 'amount' => '301']))->assertStatus(422);
        $this->postOperation($ctx, $payment);
        $this->assertSame('300.00', (string) app(FinanceOperations::class)->remaining($expense));
        $this->post(route('finance.reviews.post', FinancePostingRequest::query()->where('source_id', $payment->id)->firstOrFail()->id))->assertStatus(422);
    }

    public function test_deposit_needs_no_order_and_foreign_money_requires_approved_rate_and_matching_account(): void
    {
        $this->freezeTime();
        $ctx = $this->company();
        $customer = Customer::create(['business_id' => $ctx['business']->id, 'customer_code' => 'BUY-1', 'name' => 'Buyer', 'country_code' => 'AE', 'currency' => 'USD', 'active' => true]);
        $rate = FinanceExchangeRate::create(['business_id' => $ctx['business']->id, 'currency' => 'USD', 'base_currency' => 'UGX', 'rate_to_base' => '3700', 'effective_on' => now()->toDateString(), 'status' => 'approved', 'created_by' => $ctx['preparer']->id, 'approved_by' => $ctx['reviewer']->id, 'approved_at' => now(), 'source_note' => 'Manually verified bank quotation']);
        $profile = app(FinanceBankProfiles::class)->create($ctx['business'], ['label' => 'USD Bank', 'institution' => 'Example bank', 'currency' => 'USD'], $ctx['preparer']->id);
        $data = $this->data(['kind' => 'customer_deposit', 'currency' => 'USD', 'amount' => '100', 'customer_id' => $customer->id, 'bank_profile_id' => $profile->id]);
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), $data)->assertSessionHasErrors('exchange_rate_id');
        $deposit = $this->create($ctx, [...$data, 'exchange_rate_id' => $rate->id]);
        $journal = $this->postOperation($ctx, $deposit);
        $this->assertSame('370000.00', (string) app(FinanceMath::class)->money((string) $journal->lines->first()->base_debit));
        $this->assertNull($deposit->sales_order_id);
        $this->assertSame('100.00', (string) app(FinanceOperations::class)->remaining($deposit));
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), $this->data(['kind' => 'customer_refund', 'parent_id' => $deposit->id, 'currency' => 'USD', 'amount' => '10', 'exchange_rate_id' => $rate->id]))->assertStatus(422);
    }

    public function test_tenant_permission_validation_and_immutable_snapshot_are_enforced(): void
    {
        $ctx = $this->company();
        $other = $this->company('OTHER');
        $source = $this->create($ctx, $this->data(['staff_person_id' => $ctx['person']->id]));
        $this->actingAs($other['preparer'])->withSession(['active_business_id' => $other['business']->id])->get(route('finance.money.show', $source->id))->assertNotFound();
        $this->post(route('finance.money.store'), $this->data(['staff_person_id' => $ctx['person']->id]))->assertNotFound();
        $viewer = User::factory()->create();
        BusinessMembership::create(['business_id' => $ctx['business']->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), $this->data(['staff_person_id' => $ctx['person']->id]))->assertForbidden();
        $this->actingAs($ctx['preparer'])->withSession(['active_business_id' => $ctx['business']->id])->post(route('finance.money.store'), [])->assertSessionHasErrors(['kind', 'amount', 'currency', 'event_on', 'idempotency_key']);
        $source->update(['amount' => '50']);
        $this->post(route('finance.reviews.request', ['type' => 'money_operation', 'source' => $source->id]), ['posting_on' => now()->toDateString(), 'basis_notes' => 'This modified immutable record should be refused.'])->assertStatus(422);
        $this->assertSame(0, FinanceJournal::count());
    }

    public function test_partial_supplier_bills_value_only_their_accepted_receipt_quantities_at_each_historical_rate(): void
    {
        $this->freezeTime();
        $ctx = $this->company();
        $b = $ctx['business'];
        $owner = $ctx['preparer'];
        $product = Product::create(['business_id' => $b->id, 'name' => 'Mango', 'sku' => 'MANGO', 'base_unit' => 'kg', 'active' => true]);
        $purchase = PurchaseOrder::create(['business_id' => $b->id, 'reference' => 'PO-PARTIAL', 'kind' => 'market', 'product_id' => $product->id, 'vendor_name' => 'Market farmer', 'purchased_on' => now()->toDateString(), 'quantity' => '20', 'unit_price' => '1', 'estimated_total' => '20', 'currency' => 'USD', 'status' => 'part_received', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $owner->id]);
        $batch = StockBatch::create(['business_id' => $b->id, 'product_id' => $product->id, 'batch_code' => 'BATCH-PARTIAL', 'received_on' => now()->toDateString(), 'received_quantity' => '10', 'available_quantity' => '10', 'created_by' => $owner->id]);
        $receipt = GoodsReceipt::create(['business_id' => $b->id, 'reference' => 'GR-PARTIAL', 'purchase_order_id' => $purchase->id, 'batch_id' => $batch->id, 'received_quantity' => '10', 'accepted_quantity' => '10', 'rejected_quantity' => '0', 'received_on' => now()->toDateString(), 'posted_by' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        $rates = [];
        foreach (['3700', '3800'] as $value) {
            $rate = app(FinanceRates::class)->propose($b, ['currency' => 'USD', 'rate_to_base' => $value, 'effective_on' => now()->toDateString(), 'source_note' => 'Approved manual purchase settlement rate'], $owner->id);
            app(FinanceRates::class)->approve($rate, $ctx['reviewer']->id);
            $rates[] = $rate;
        }
        foreach ([['4', 'INV-PART-A'], ['6', 'INV-PART-B']] as $i => [$quantity,$reference]) {
            $this->actingAs($owner)->post(route('finance.bills.store', $purchase->id), ['vendor_invoice_reference' => $reference, 'vendor_invoice_date' => now()->toDateString(), 'accepted_quantity' => $quantity, 'due_on' => now()->addDays(7)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
            $bill = FinanceSupplierBill::query()->where('vendor_invoice_reference', $reference)->firstOrFail();
            $this->postOperation($ctx, $bill, 'supplier_bill', $rates[$i]->id);
        }
        $this->assertDatabaseCount('finance_bill_receipts', 2);
        $this->assertTrue(app(FinanceCostBasis::class)->receiptUnitBase($receipt)->isEqualTo('3760'));
        $this->actingAs($owner)->post(route('finance.bills.store', $purchase->id), ['vendor_invoice_reference' => 'INV-TOO-MUCH', 'vendor_invoice_date' => now()->toDateString(), 'accepted_quantity' => '1'])->assertStatus(422);
        $this->assertDatabaseCount('finance_supplier_bills', 2);
        $this->get(route('finance.documents'))->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 0);
    }

    public function test_foreign_deposit_allocation_clears_invoice_carrying_and_prevents_second_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 09:00:00', 'UTC'));
        $ctx = $this->company();
        $b = $ctx['business'];
        $owner = $ctx['preparer'];
        $customer = Customer::create(['business_id' => $b->id, 'customer_code' => 'IMPORT', 'name' => 'Importer', 'country_code' => 'AE', 'currency' => 'USD', 'active' => true]);
        $rates = [];
        foreach (['3700', '3800'] as $value) {
            $rate = app(FinanceRates::class)->propose($b, ['currency' => 'USD', 'rate_to_base' => $value, 'effective_on' => now()->toDateString(), 'source_note' => 'Bank approved manual recognition rate'], $owner->id);
            app(FinanceRates::class)->approve($rate, $ctx['reviewer']->id);
            $rates[] = $rate;
        }
        $profile = app(FinanceBankProfiles::class)->create($b, ['label' => 'US dollars', 'institution' => 'Example bank', 'currency' => 'USD'], $owner->id);
        $deposit = $this->create($ctx, $this->data(['kind' => 'customer_deposit', 'customer_id' => $customer->id, 'currency' => 'USD', 'amount' => '100', 'exchange_rate_id' => $rates[0]->id, 'bank_profile_id' => $profile->id]));
        $this->postOperation($ctx, $deposit);
        $request = SalesRequest::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'reference' => 'REQUEST-INV', 'status' => 'confirmed', 'created_by' => $owner->id]);
        $order = SalesOrder::create(['business_id' => $b->id, 'reference' => 'SO-INVOICE', 'buyer_confirmation' => 'Verified buyer purchase order', 'sales_request_id' => $request->id, 'customer_id' => $customer->id, 'status' => 'confirmed', 'pricing_status' => 'accepted', 'currency' => 'USD', 'subtotal' => '100', 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => '100', 'confirmed_by' => $owner->id, 'confirmed_at' => now()]);
        $shipment = ExportShipment::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'sales_order_id' => $order->id, 'reference' => 'SHIP-INVOICE', 'transport_mode' => 'air', 'status' => 'dispatched', 'origin_country' => 'UG', 'destination_country' => 'AE', 'created_by' => $owner->id]);
        $invoice = CommercialInvoice::create(['business_id' => $b->id, 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id, 'customer_id' => $customer->id, 'reference' => 'CI-DEPOSIT', 'currency' => 'USD', 'subtotal' => '100', 'discount_amount' => '0', 'tax_amount' => '0', 'freight_amount' => '0', 'insurance_amount' => '0', 'grand_total' => '100', 'posting_status' => 'not_posted', 'issued_by' => $owner->id, 'issued_at' => now(), 'data_snapshot' => [], 'html_snapshot' => 'Invoice', 'sha256' => hash('sha256', 'Invoice'), 'due_on' => now()->addDays(7)->toDateString()]);
        $this->postOperation($ctx, $invoice, 'commercial_invoice', $rates[1]->id);
        foreach (['40', '60'] as $amount) {
            $allocation = $this->create($ctx, $this->data(['kind' => 'customer_apply', 'currency' => 'USD', 'amount' => $amount, 'parent_id' => $deposit->id, 'commercial_invoice_id' => $invoice->id]));
            $this->postOperation($ctx, $allocation);
        }
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($invoice)->isZero());
        $this->assertSame(0, app(FinanceDocuments::class)->outstandingQuery($b, true)->count());
        $this->assertTrue(app(FinanceOperations::class)->remaining($deposit)->isZero());
        $totalLoss = FinanceJournalLine::query()->where('business_id', $b->id)->whereHas('account', fn ($q) => $q->where('system_key', 'fx_loss'))->sum('base_debit');
        $this->assertSame(10000, (int) $totalLoss);
        $this->actingAs($owner)->get(route('finance.position'))->assertOk()->assertSee('Business money position');
        $this->get(route('sales-orders.show', [$order->id, 'tab' => 'money']))->assertOk()->assertSee('Order cost sheet');
        $this->post(route('finance.payments.store'), ['direction' => 'in', 'commercial_invoice_id' => $invoice->id, 'cash_account_key' => 'bank', 'bank_profile_id' => $profile->id, 'amount' => '1', 'payment_on' => now()->toDateString(), 'external_reference' => 'DUPLICATE-PAY', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');
    }

    public function test_money_evidence_is_private_and_frozen_before_review_and_tampering_blocks_posting(): void
    {
        Storage::fake('local');
        $ctx = $this->company();
        $source = $this->create($ctx, $this->data(['kind' => 'expense', 'amount' => '200', 'category' => 'utilities']));
        $this->actingAs($ctx['preparer'])->post(route('finance.money.evidence.upload', $source->id), ['file' => UploadedFile::fake()->create('receipt.pdf', 2, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $source->refresh();
        $evidence = $source->data_snapshot['evidence'][0];
        Storage::disk('local')->assertExists($evidence['path']);
        $this->get(route('finance.money.evidence', [$source->id, 0]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('finance.reviews.request', ['type' => 'money_operation', 'source' => $source->id]), ['posting_on' => now()->toDateString(), 'basis_notes' => 'Attached private receipt checked against actual expense evidence.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('finance.money.evidence.upload', $source->id), ['file' => UploadedFile::fake()->create('second.pdf', 2, 'application/pdf')])->assertStatus(422);
        Storage::disk('local')->put($evidence['path'], 'Changed evidence');
        $this->actingAs($ctx['reviewer'])->post(route('finance.reviews.approve', FinancePostingRequest::firstOrFail()->id), ['notes' => 'Review must refuse changed supporting evidence.'])->assertStatus(422);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('finance_posting_requests', 1);
    }

    public function test_account_transfer_has_two_balanced_money_sides_and_its_receiving_side_matches_the_bank(): void
    {
        $this->freezeTime();
        $c = $this->company();
        $profile = app(FinanceBankProfiles::class)->create($c['business'], ['label' => 'UGX Collections', 'institution' => 'Example bank', 'currency' => 'UGX'], $c['preparer']->id);
        $transfer = $this->create($c, $this->data(['kind' => 'transfer', 'amount' => '100', 'destination_bank_profile_id' => $profile->id]));
        $journal = $this->postOperation($c, $transfer);
        $this->assertSame(2, $journal->lines()->count());
        $statement = FinanceBankStatement::create(['business_id' => $c['business']->id, 'account_id' => $profile->account_id, 'reference' => 'BANK-TRANSFER', 'currency' => 'UGX', 'period_from' => now()->toDateString(), 'period_to' => now()->toDateString(), 'opening_balance' => '0', 'closing_balance' => '100', 'credits' => '100', 'debits' => '0', 'file_sha256' => hash('sha256', 'Transfer statement'), 'filename' => 'statement.csv', 'status' => 'open', 'imported_by' => $c['preparer']->id]);
        $line = FinanceBankStatementLine::create(['business_id' => $c['business']->id, 'statement_id' => $statement->id, 'line_number' => 1, 'transaction_on' => now()->toDateString(), 'direction' => 'credit', 'amount' => '100', 'bank_reference' => 'VOUCHER-001', 'description' => 'Transfer from cash']);
        $this->actingAs($c['preparer'])->get(route('finance.bank.show', $statement->id))->assertOk()->assertSee($transfer->reference);
        $this->post(route('finance.bank.match', [$statement->id, $line->id]), ['candidate' => 'money_transfer_in:'.$transfer->id, 'note' => 'Actual receipt to this named bank account.'])->assertRedirect()->assertSessionHasNoErrors();
        $match = FinanceBankMatch::firstOrFail();
        $this->actingAs($c['reviewer'])->post(route('finance.bank.confirm', $match->id), ['note' => 'Independently checked the bank transfer.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $match->fresh()->status);
        $this->assertSame($profile->account_id, $journal->lines()->where('debit', '>', 0)->firstOrFail()->account_id);
    }

    public function test_uganda_business_date_accepts_the_current_local_day_before_utc_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 22:15:00', 'UTC'));
        $c = $this->company();
        $operation = $this->create($c, $this->data(['kind' => 'expense', 'category' => 'utilities', 'event_on' => '2026-10-27']));
        $this->postOperation($c, $operation);
        $this->actingAs($c['preparer'])->get(route('finance.position'))->assertOk()->assertViewHas('date', '2026-10-27');
    }

    public function test_already_paid_expense_posts_cash_and_cost_once_and_cannot_be_paid_again(): void
    {
        $c = $this->company();
        $expense = $this->create($c, $this->data(['kind' => 'paid_expense', 'category' => 'transport', 'amount' => '25000']));
        $journal = $this->postOperation($c, $expense);
        $this->assertTrue($journal->lines->contains(fn ($line) => $line->account->system_key === 'cash' && (int) $line->credit === 25000));
        $this->assertFalse($journal->lines->contains(fn ($line) => $line->account->system_key === 'expense_payable'));
        $this->actingAs($c['preparer'])->post(route('finance.money.store'), $this->data(['kind' => 'expense_payment', 'parent_id' => $expense->id, 'amount' => '25000']))->assertStatus(422);
        $this->assertDatabaseCount('finance_journals', 1);
        $this->get(route('finance.money.create', ['kind' => 'paid_expense']))->assertOk()->assertSee('Money account');
    }
}
