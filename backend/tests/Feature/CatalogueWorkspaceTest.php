<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceAccount;
use App\Models\FinanceCreditNote;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\LabelGeneration;
use App\Models\LabelPrintBatch;
use App\Models\LabelTemplate;
use App\Models\PackingJob;
use App\Models\PartnerFollowup;
use App\Models\Product;
use App\Models\QrIdentity;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesRequest;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogueWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $code = 'CAT', string $role = 'owner'): array
    {
        $business = Business::create(['name' => $code.' Exports', 'code' => $code, 'country_code' => 'UG', 'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'product']);
        $user = User::factory()->create();
        BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $product = Product::create(['business_id' => $business->id, 'name' => 'Fresh avocado', 'sku' => 'AVO', 'base_unit' => 'kg', 'active' => true]);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Sample Buyer', 'customer_code' => 'BUY', 'country_code' => 'AE', 'currency' => 'UGX', 'active' => true]);

        return compact('business', 'user', 'product', 'customer');
    }

    private function signIn(array $c): static
    {
        return $this->actingAs($c['user'])->withSession(['active_business_id' => $c['business']->id]);
    }

    private function order(array $c, string $reference, string $date): SalesOrder
    {
        $request = SalesRequest::create(['business_id' => $c['business']->id, 'reference' => 'REQ-'.$reference, 'customer_id' => $c['customer']->id, 'source' => 'manual', 'status' => 'open', 'created_by' => $c['user']->id]);
        $order = SalesOrder::create(['business_id' => $c['business']->id, 'reference' => $reference, 'sales_request_id' => $request->id, 'customer_id' => $c['customer']->id, 'status' => 'confirmed', 'currency' => 'UGX', 'pricing_status' => 'accepted', 'buyer_confirmation' => 'Buyer agreed in writing', 'confirmed_by' => $c['user']->id, 'grand_total' => '90000', 'confirmed_at' => $date.' 10:00:00']);
        $order->forceFill(['created_at' => $date.' 10:00:00'])->save();
        SalesOrderLine::create(['business_id' => $c['business']->id, 'sales_order_id' => $order->id, 'product_id' => $c['product']->id, 'quantity' => '10', 'unit_price' => '9000', 'line_total' => '90000']);

        return $order;
    }

    public function test_customer_background_create_and_edit_validate_countries_and_log_changes(): void
    {
        $c = $this->context();
        $data = ['name' => 'Alice Buyer', 'customer_code' => 'ALICE', 'country_code' => 'KE', 'currency' => 'UGX', 'city' => 'Nairobi', 'business_name' => 'Alice Produce Ltd', 'business_email' => 'accounts@example.test', 'location' => 'Market road, stall 10', 'email' => 'alice@example.test', 'active' => 1];
        $this->signIn($c)->postJson(route('customers.store'), $data)->assertOk()->assertJsonPath('message', 'Customer added. Refresh the directory when you are ready.');
        $saved = Customer::where('customer_code', 'ALICE')->firstOrFail();
        $this->assertSame('Alice Produce Ltd', $saved->business_name);
        $this->assertSame('KE', $saved->country_code);
        $this->putJson(route('customers.update', $saved->id), [...$data, 'location' => 'Updated address'])->assertOk();
        $this->assertSame('Updated address', $saved->fresh()->location);
        $this->get(route('customers.index', ['tables' => ['customers__page' => ['search' => 'ALICE']]]))->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $saved->id);
        $this->postJson(route('customers.store'), [...$data, 'customer_code' => 'BAD', 'country_code' => 'ZZ'])->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->assertDatabaseHas('platform_audit_events', ['business_id' => $c['business']->id, 'action' => 'customer.updated']);
        $this->get(route('customers.index'))->assertOk()->assertSee('data-remote-dialog', false)->assertDontSee('name="product_ids[]"', false);
    }

    public function test_statements_group_weeks_filter_dates_and_keep_tenants_and_finance_private(): void
    {
        $a = $this->context();
        $b = $this->context('OTHER');
        $this->order($a, 'WEEK-A', '2026-10-26');
        $this->order($a, 'OLD-ORDER', '2026-09-01');
        $this->order($b, 'FOREIGN-ORDER', '2026-10-26');
        $this->signIn($a)->get(route('catalogue.statement', ['customer', $a['customer']->id, 'from' => '2026-10-25', 'to' => '2026-10-31']))->assertOk()->assertSee('26 Oct 2026')->assertSee('01 Nov 2026')->assertSee('WEEK-A')->assertDontSee('OLD-ORDER')->assertDontSee('FOREIGN-ORDER');
        $this->putJson(route('statements.week'), ['statement_week_start' => 0])->assertOk();
        $this->assertSame(0, (int) $a['business']->fresh()->statement_week_start);
        $this->get(route('catalogue.statement', ['customer', $a['customer']->id, 'from' => '2026-10-25', 'to' => '2026-10-31']))->assertOk()->assertSee('25 Oct 2026')->assertSee('31 Oct 2026');
        $this->get(route('catalogue.statement', ['customer', $b['customer']->id]))->assertNotFound();
        $sales = User::factory()->create();
        BusinessMembership::create(['business_id' => $a['business']->id, 'user_id' => $sales->id, 'role' => 'sales', 'active' => true]);
        $this->actingAs($sales)->get(route('catalogue.statement', ['customer', $a['customer']->id]))->assertOk()->assertDontSee('Outstanding ·')->assertDontSee('Posted invoice payments');
        $this->putJson(route('statements.week'), ['statement_week_start' => 3])->assertForbidden();
        $this->get(route('partners.show', ['customer', $a['customer']->id, 'activity' => 'documents']))->assertForbidden();
    }

    public function test_product_skus_are_generated_uniquely_and_codes_are_tenant_scoped(): void
    {
        $a = $this->context();
        $b = $this->context('OTHER');
        $this->signIn($a);
        foreach ([1, 2] as $n) {
            $this->postJson(route('products.store'), ['name' => 'Fresh Chilli', 'base_unit' => 'kg'])->assertOk();
        }
        $this->assertDatabaseHas('products', ['business_id' => $a['business']->id, 'sku' => 'FRESH-CHILLI']);
        $this->assertDatabaseHas('products', ['business_id' => $a['business']->id, 'sku' => 'FRESH-CHILLI-2']);
        $this->postJson(route('products.codes.store', $a['product']->id), ['code' => 'CLIENT-AVO', 'customer_id' => $a['customer']->id, 'country_code' => 'AE'])->assertOk();
        $this->postJson(route('products.codes.store', $a['product']->id), ['code' => 'FOREIGN', 'customer_id' => $b['customer']->id])->assertUnprocessable();
        $this->postJson(route('products.codes.store', $a['product']->id), ['code' => 'CLIENT-AVO', 'customer_id' => $a['customer']->id, 'country_code' => 'AE'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('product_codes', 1);
        $this->get(route('catalogue.statement', ['product', $a['product']->id]))->assertOk()->assertSee('CLIENT-AVO');
        $viewer = $this->context('VIEW', 'viewer');
        $this->signIn($viewer)->get(route('catalogue.statement', ['product', $viewer['product']->id]))->assertOk()->assertDontSee('Gross profit')->assertDontSee('Net sales');
        $this->postJson(route('products.codes.store', $viewer['product']->id), ['code' => 'NO-ACCESS'])->assertForbidden();
    }

    public function test_secret_qr_pins_can_be_rotated_without_changing_the_qr_link(): void
    {
        $c = $this->context();
        $this->signIn($c)->putJson(route('products.qr.update', $c['product']->id), ['access' => 'pin'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->signIn($c)->putJson(route('products.qr.update', $c['product']->id), ['access' => 'pin', 'pin' => '123456'])->assertOk();
        $qr = QrIdentity::firstOrFail();
        $this->assertNotSame('123456', $qr->access_pin_hash);
        $token = $qr->public_token;
        $this->get(route('qr.public', $token))->assertOk()->assertSee('Protected product page')->assertDontSee('Fresh avocado');
        $this->postJson(route('qr.unlock', $token), ['pin' => 'wrong'])->assertUnprocessable();
        $this->post(route('qr.unlock', $token), ['pin' => '123456'])->assertRedirect();
        $this->get(route('qr.public', $token))->assertOk()->assertSee('Fresh avocado');
        $this->putJson(route('products.qr.update', $c['product']->id), ['access' => 'pin', 'pin' => '654321'])->assertOk();
        $this->assertSame($token, $qr->fresh()->public_token);
        $this->get(route('qr.public', $token))->assertOk()->assertDontSee('Fresh avocado');
        $this->post(route('qr.unlock', $token), ['pin' => '654321'])->assertRedirect();
        $this->putJson(route('products.qr.update', $c['product']->id), ['access' => 'hidden'])->assertOk();
        $this->get(route('qr.public', $token))->assertNotFound();
    }

    public function test_cancelled_unused_orders_can_be_archived_and_restored_with_history(): void
    {
        $c = $this->context();
        $order = $this->order($c, 'CANCEL-ME', '2026-10-26');
        $this->signIn($c)->postJson(route('sales-orders.archive', $order->id), ['reason' => 'Do not hide a live order'])->assertUnprocessable();
        $this->postJson(route('order-work.close', $order->id), ['action' => 'cancel', 'reason' => 'Customer cancelled before work began'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->postJson(route('sales-orders.archive', $order->id), ['reason' => 'Archive the cancelled duplicate'])->assertOk();
        $this->assertNotNull($order->fresh()->archived_at);
        $this->get(route('sales-orders.index'))->assertOk()->assertDontSee('CANCEL-ME');
        $this->get(route('partners.show', ['customer', $c['customer']->id, 'activity' => 'orders']))->assertOk()->assertDontSee('CANCEL-ME');
        $this->get(route('partners.show', ['customer', $c['customer']->id, 'activity' => 'orders', 'archived' => 1]))->assertOk()->assertSee('CANCEL-ME');
        $this->postJson(route('sales-orders.archive', $order->id), ['reason' => 'Bring cancelled history back', 'restore' => 1])->assertOk();
        $this->assertNull($order->fresh()->archived_at);
    }

    public function test_sticker_rows_barcode_logo_compression_and_optional_print_note(): void
    {
        $c = $this->context();
        $job = PackingJob::create(['business_id' => $c['business']->id, 'reference' => 'PK', 'product_id' => $c['product']->id, 'status' => 'completed', 'created_by' => $c['user']->id]);
        $batch = StockBatch::create(['business_id' => $c['business']->id, 'product_id' => $c['product']->id, 'batch_code' => 'BT', 'source_reference' => 'FARM-01', 'received_on' => '2026-10-26']);
        $carton = Carton::create(['business_id' => $c['business']->id, 'carton_code' => 'CTN-1', 'packing_job_id' => $job->id, 'net_weight_kg' => '10', 'gross_weight_kg' => '11', 'status' => 'packed']);
        CartonContent::create(['business_id' => $c['business']->id, 'carton_id' => $carton->id, 'batch_id' => $batch->id, 'quantity' => '10']);
        $input = ['name' => 'Flexible label', 'width_mm' => 100, 'height_mm' => 70, 'dpi' => 203, 'qr_level' => 'none', 'qr_visibility' => 'internal', 'include_barcode' => 1, 'visible_fields' => ['product', 'net'], 'layout' => ['mode' => 'bitmap', 'barcode_field' => 'farmer_code', 'fields' => ['product' => ['font_mm' => 4], 'net' => ['font_mm' => 3]]], 'logo' => UploadedFile::fake()->image('logo.png', 2400, 960)];
        $this->signIn($c)->post(route('labels.store'), $input)->assertSessionHasNoErrors();
        $template = LabelTemplate::firstOrFail();
        $this->assertLessThanOrEqual(524288, strlen(base64_decode($template->layout['logo_png'])));
        $this->assertSame('farmer_code', $template->layout['barcode_field']);
        $this->post(route('labels.batches.store'), ['template_id' => $template->id, 'carton_ids' => [$carton->id], 'copies' => 1, 'request_key' => Str::uuid()->toString()])->assertSessionHasNoErrors();
        $printBatch = LabelPrintBatch::firstOrFail();
        $this->post(route('labels.batches.output', $printBatch->id), ['format' => 'pdf', 'disposition' => 'inline'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'inline; filename="sticker-batch-'.$printBatch->id.'.pdf"');
        $c['business']->update(['label_verification_required' => true]);
        $this->postJson(route('labels.batches.store'), ['template_id' => $template->id, 'carton_ids' => [$carton->id], 'copies' => 1, 'request_key' => Str::uuid()->toString()])->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->putJson(route('products.qr.update', $c['product']->id), ['access' => 'pin', 'pin' => '441122'])->assertOk();
        $template->update(['qr_level' => 'product', 'qr_visibility' => 'protected']);
        $this->post(route('labels.batches.store'), ['template_id' => $template->id, 'carton_ids' => [$carton->id], 'copies' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $label = LabelGeneration::query()->latest('id')->firstOrFail();
        $identity = QrIdentity::query()->where('record_id', $c['product']->id)->where('level', 'product')->firstOrFail();
        $scan = route('qr.public', ['token' => $identity->public_token, 'label' => $label->scan_token]);
        $this->post(route('labels.verify', $label->id), ['scan' => $scan, 'reason' => 'Scanned the printed product QR code', 'applied' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($label->fresh()->verified_at);
        $this->postJson(route('labels.verify', $label->id), ['scan' => route('qr.public', $identity->public_token), 'reason' => 'A product URL without a sticker revision', 'applied' => 1])->assertUnprocessable()->assertJsonValidationErrors('scan');

    }

    public function test_statements_use_posted_balances_revenue_and_product_stock_costs(): void
    {
        $c = $this->context();
        $order = $this->order($c, 'SALES-1', '2026-10-26');
        $shipment = ExportShipment::create(['business_id' => $c['business']->id, 'reference' => 'SHP-1', 'customer_id' => $c['customer']->id, 'sales_order_id' => $order->id, 'origin_country' => 'UG', 'destination_country' => 'AE', 'status' => 'dispatched', 'created_by' => $c['user']->id]);
        $invoice = CommercialInvoice::create(['business_id' => $c['business']->id, 'reference' => 'INV-1', 'customer_id' => $c['customer']->id, 'sales_order_id' => $order->id, 'shipment_id' => $shipment->id, 'currency' => 'UGX', 'subtotal' => '1000', 'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '1000', 'posting_status' => 'posted', 'data_snapshot' => ['lines' => [['product_id' => $c['product']->id, 'line_total' => '1000']]], 'html_snapshot' => '<p>Invoice</p>', 'sha256' => hash('sha256', 'Invoice'), 'issued_by' => $c['user']->id, 'issued_at' => '2026-10-26 10:00:00']);
        $account = FinanceAccount::create(['business_id' => $c['business']->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income', 'system_key' => 'export_sales']);
        $journal = FinanceJournal::create(['business_id' => $c['business']->id, 'reference' => 'JRN-1', 'source_type' => 'commercial_invoice', 'source_id' => $invoice->id, 'posted_on' => '2026-10-26', 'currency' => 'UGX', 'base_currency' => 'UGX', 'rate_to_base' => '1', 'description' => 'Posted sales', 'created_by' => $c['user']->id, 'approved_by' => $c['user']->id]);
        FinanceJournalLine::create(['business_id' => $c['business']->id, 'journal_id' => $journal->id, 'account_id' => $account->id, 'credit' => '1000', 'base_credit' => '1000']);
        $batch = StockBatch::create(['business_id' => $c['business']->id, 'product_id' => $c['product']->id, 'batch_code' => 'COST-BATCH']);
        FinanceInventoryRelief::create(['business_id' => $c['business']->id, 'reference' => 'COGS-1', 'kind' => 'cogs', 'shipment_id' => $shipment->id, 'currency' => 'UGX', 'base_amount' => '600', 'data_snapshot' => ['lines' => [['batch_id' => $batch->id, 'base_cost' => '600']]], 'sha256' => hash('sha256', 'cost'), 'posting_status' => 'posted', 'created_by' => $c['user']->id]);
        $this->signIn($c)->get(route('catalogue.statement', ['customer', $c['customer']->id]))->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['Outstanding · UGX'] === '1000.00');
        $this->get(route('catalogue.statement', ['product', $c['product']->id]))->assertOk()->assertViewHas('activities', fn ($rows) => $rows->contains(fn ($row) => $row->kind === 'invoice' && $row->reference === 'INV-1'))->assertViewHas('metrics', fn ($metrics) => $metrics['Net sales · UGX'] === '1000.00' && $metrics['Recorded cost of sales · UGX'] === '600.00' && $metrics['Gross profit · UGX'] === '400.00');
        $credit = FinanceCreditNote::create(['business_id' => $c['business']->id, 'commercial_invoice_id' => $invoice->id, 'reference' => 'CREDIT-1', 'currency' => 'UGX', 'amount' => '1000', 'reason' => 'Full invoice credit', 'posting_status' => 'posted', 'data_snapshot' => [], 'html_snapshot' => '<p>Credit</p>', 'sha256' => hash('sha256', 'credit'), 'created_by' => $c['user']->id, 'issued_at' => '2026-10-26 12:00:00']);
        $creditJournal = FinanceJournal::create(['business_id' => $c['business']->id, 'reference' => 'JRN-CREDIT', 'source_type' => 'credit_note', 'source_id' => $credit->id, 'posted_on' => '2026-10-26', 'currency' => 'UGX', 'base_currency' => 'UGX', 'rate_to_base' => '1', 'description' => 'Posted full credit', 'created_by' => $c['user']->id, 'approved_by' => $c['user']->id]);
        FinanceJournalLine::create(['business_id' => $c['business']->id, 'journal_id' => $creditJournal->id, 'account_id' => $account->id, 'debit' => '1000', 'base_debit' => '1000']);
        $invoice->update(['posting_status' => 'credited']);
        $this->get(route('catalogue.statement', ['product', $c['product']->id]))->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['Net sales · UGX'] === '0.00' && $metrics['Gross profit · UGX'] === '-600.00');

    }

    public function test_followups_archive_and_restore_are_scoped_and_audited(): void
    {
        $c = $this->context();
        $staff = StaffPerson::create(['business_id' => $c['business']->id, 'staff_code' => 'STAFF-1', 'name' => 'Operations Lead', 'kind' => 'employee', 'active' => true]);
        $f = PartnerFollowup::create(['business_id' => $c['business']->id, 'customer_id' => $c['customer']->id, 'staff_person_id' => $staff->id, 'title' => 'Confirm next delivery', 'note' => 'Customer requested a delivery update.', 'due_at' => '2026-10-29 10:00:00', 'status' => 'open', 'created_by' => $c['user']->id]);
        $this->signIn($c)->postJson(route('partners.followup.archive', ['customer', $c['customer']->id, $f->id]), ['reason' => 'Follow-up entered in error'])->assertOk();
        $this->assertSoftDeleted($f);
        $this->get(route('partners.show', ['customer', $c['customer']->id, 'activity' => 'followups', 'archived' => 1]))->assertOk()->assertSee('Confirm next delivery');
        $this->postJson(route('partners.followup.archive', ['customer', $c['customer']->id, $f->id]), ['reason' => 'Restore the delivery reminder', 'restore' => 1])->assertOk();
        $this->assertNotSoftDeleted($f);
        $this->assertDatabaseHas('platform_audit_events', ['business_id' => $c['business']->id, 'action' => 'partner.followup.archive']);
        $other = $this->context('OTHER');
        $this->signIn($other)->postJson(route('partners.followup.archive', ['customer', $c['customer']->id, $f->id]), ['reason' => 'This must remain tenant scoped'])->assertNotFound();
    }
}
