<?php

namespace Tests\Feature;

use App\Models\BusinessMembership;
use App\Models\CashClose;
use App\Models\CommercialInvoice;
use App\Models\ExternalProviderConnection;
use App\Models\FinanceBankLedgerCheck;
use App\Models\FinanceBankStatement;
use App\Models\FinancePayrollRun;
use App\Models\LabelMediaProfile;
use App\Models\ProductImage;
use App\Models\ReleaseAcceptanceCheck;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceBankAuthenticity;
use App\Services\FinanceDocuments;
use App\Services\FinanceInventoryReconciliation;
use App\Services\FinanceOwnerPosition;
use App\Services\FinanceTreasury;
use App\Services\ReleaseIntegrityInspector;
use App\Support\BusinessPermissions;
use App\Support\ExporterDemoContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExporterDemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_connected_demo_preserves_existing_accounts_and_reconciles_every_model_and_workflow(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        Storage::fake('local');
        Http::preventStrayRequests();
        Mail::fake();
        $original = User::factory()->create(['name' => 'Original existing account']);
        $originalData = $original->fresh()->getRawOriginal();
        $this->assertSame(0, Artisan::call('expo:demo-seed'), Artisan::output());
        $demo = app(ExporterDemoContext::class);
        $b = $demo->business;
        $this->assertSame($originalData, $original->fresh()->getRawOriginal());
        $this->assertSame(11, User::count());
        $this->assertTrue($demo->user('superadmin')->is_platform_admin);
        $this->assertFalse($demo->user('owner')->is_platform_admin);
        $this->assertSame(0, $demo->user('superadmin')->memberships()->count());
        $reportPath = 'demo/coverage-'.$b->id.'.json';
        $report = json_decode(Storage::disk('local')->get($reportPath), true);
        $this->assertCount(165, $report['model_counts']);
        $this->assertSame([], array_keys(array_filter($report['model_counts'], fn ($n) => $n === 0)));
        $this->assertCount(2, $report['private_demo_links']);
        $integrity = app(ReleaseIntegrityInspector::class)->inspect($b);
        $this->assertSame([], array_values(array_filter($integrity['checks'], fn ($c) => $c['status'] === 'fail')), json_encode($integrity['checks']));
        $inventory = app(FinanceInventoryReconciliation::class)->report($b, '2026-10-27');
        $this->assertTrue($inventory['matches'], implode('; ', $inventory['issues']));
        $this->assertTrue(app(FinanceTreasury::class)->countIsCurrent(CashClose::firstOrFail()));
        $this->assertTrue(BigDecimal::of((string) StockMovement::where('batch_id', $demo->get('opening_batch')->id)->sum('quantity'))->isEqualTo('189'));
        $invoice = CommercialInvoice::firstOrFail();
        $this->assertSame('USD', $invoice->currency);
        $this->assertTrue(BigDecimal::of($invoice->grand_total)->isEqualTo('180'));
        $this->assertSame('dispatched', $demo->get('shipment')->status);
        $this->assertTrue(app(FinanceDocuments::class)->outstanding($demo->get('bill_market'), false)->isEqualTo('57000'));
        $this->assertSame('25000.00', FinancePayrollRun::where('period_from', 'like', '2026-10-27%')->firstOrFail()->data_snapshot['gross']);
        $this->assertFalse(app(FinanceBankAuthenticity::class)->current(FinanceBankStatement::where('status', 'reconciled')->firstOrFail()));
        $this->assertSame('pending', FinanceBankLedgerCheck::firstOrFail()->status);
        $this->assertNull(LabelMediaProfile::firstOrFail()->tested_at);
        $this->assertSame(0, ReleaseAcceptanceCheck::where('status', '!=', 'pending')->count());
        $this->assertFalse(ExternalProviderConnection::firstOrFail()->active);
        Mail::assertNothingOutgoing();
        $hashes = User::pluck('password', 'id')->all();
        $tables = [];
        foreach ($report['model_counts'] as $model => $count) {
            $class = 'App\\Models\\'.$model;
            $tables[$model] = $class::count();
        }
        $this->assertSame(0, Artisan::call('expo:demo-seed'), Artisan::output());
        $this->assertSame($hashes, User::pluck('password', 'id')->all());
        foreach ($tables as $model => $count) {
            $class = 'App\\Models\\'.$model;
            $this->assertSame($count, $class::count(), $model.' was duplicated on a repeated demo setup.');
        }$this->assertSame($report['private_demo_links'], json_decode(Storage::disk('local')->get($reportPath), true)['private_demo_links']);
        $this->post('/login', ['email' => $demo->user('superadmin')->email, 'password' => $demo->password])->assertRedirect(route('platform.overview'));
        $this->get(route('platform.overview'))->assertOk()->assertSee('DEMO');
        $this->get(route('platform.businesses.show', $b))->assertOk();
        $this->get(route('platform.health'))->assertOk();
        $this->actingAs($demo->user('owner'))->withSession(['active_business_id' => $b->id]);
        foreach (['workspace', 'sales-requests.index', 'sales-requests.create', 'sales-orders.index', 'shipments.index', 'shipments.create', 'commercial-invoices.index', 'export-documents.index', 'stock.index', 'stock.exceptions.index', 'stock.cases.index', 'suppliers.index', 'suppliers.create', 'supply-requests.index', 'supply-requests.create', 'destinations.index', 'people.index', 'staff.index', 'team.index', 'customers.index', 'products.index', 'finance.index', 'finance.money.index', 'finance.documents', 'finance.treasury.index', 'finance.trade.index', 'finance.reports.index', 'finance.controls', 'finance.inventory.index', 'finance.payroll.index', 'finance.position', 'finance.funding.index', 'finance.production-costs.index', 'finance.assets.index', 'finance.loans.index', 'labels.index', 'labels.print'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $overviewTotals = $this->get(route('finance.index'))->viewData('overviewTotals');
        $position = app(FinanceOwnerPosition::class)->report($b, now($b->timezone)->toDateString(), '100');
        $this->assertTrue($overviewTotals['money']->isEqualTo($position['money']), 'Overview '.$overviewTotals['money'].' differs from business position '.$position['money']);
        $access = app(BusinessPermissions::class);
        $membership = BusinessMembership::where('business_id', $b->id)->where('user_id', $demo->user('owner')->id)->firstOrFail();
        $checked = [];
        foreach (config('workspace-activities') as $activities) {
            foreach ($activities as $activity) {
                if (! $access->allows($membership, $activity[2])) {
                    continue;
                }$url = route($activity[1], $activity[3]);
                if (isset($checked[$url])) {
                    continue;
                }$checked[$url] = true;
                $response = $this->get($url);
                $this->assertSame(200, $response->status(), $url);
            }
        }
        $photo = ProductImage::firstOrFail();
        $this->get(route('products.images.show', [$photo->product_id, $photo->id, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('sales-orders.show', $demo->get('order')->id))->assertOk();
        $this->get(route('platform.overview'))->assertForbidden();
        $this->withSession(['active_business_id' => $demo->get('other_business')->id])->get(route('products.images.show', [$photo->product_id, $photo->id, 'thumb']))->assertNotFound();
        app()->detectEnvironment(fn () => 'production');
        $this->assertSame('r2', $photo->storageDisk());
    }

    public function test_demo_rolls_back_all_new_records_if_a_demo_email_is_already_in_use(): void
    {
        Storage::fake('local');
        $existing = User::factory()->create(['email' => 'owner@demo.boosted.test']);
        $hash = $existing->password;
        $this->artisan('expo:demo-seed')->assertFailed();
        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertFalse(User::where('email', 'superadmin@demo.boosted.test')->exists());
    }

    public function test_demo_command_refuses_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->artisan('expo:demo-seed')->assertFailed();
        $this->assertDatabaseCount('businesses', 0);
    }
}
