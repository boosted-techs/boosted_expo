<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\Product;
use App\Models\ReleaseAcceptanceCheck;
use App\Models\ReleaseAcceptanceRun;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReleaseAcceptanceManager;
use App\Services\ReleaseArtifactVerifier;
use App\Services\ReleaseBuildVerifier;
use App\Services\ReleaseGate;
use App\Services\ReleaseIntegrityInspector;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourAcceptanceAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function firm(string $code): array
    {
        $business = Business::create([
            'name' => $code.' Exporters', 'code' => $code, 'country_code' => 'UG',
            'base_currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'default_qr_level' => 'batch',
        ]);
        $users = [];
        foreach (['owner', 'admin', 'accountant', 'warehouse', 'logistics', 'viewer'] as $role) {
            $user = User::factory()->create();
            BusinessMembership::create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => $role, 'active' => true,
            ]);
            $users[$role] = $user;
        }

        return compact('business', 'users');
    }

    private function signIn(array $company, string $role): self
    {
        return $this->actingAs($company['users'][$role])
            ->withSession(['active_business_id' => $company['business']->id]);
    }

    private function begin(array $company, string $sha): ReleaseAcceptanceRun
    {
        $this->signIn($company, 'owner')->post(route('release.acceptance.begin'), [
            'artifact_sha256' => $sha,
            'assets_sha256' => str_repeat('1', 64),
            'release_label' => 'Pilot release C1',
            'scope_note' => 'Staging candidate from approved artifact, with finance and logistics sample records only.',
            'confirm_staging' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return ReleaseAcceptanceRun::query()->where('business_id', $company['business']->id)
            ->latest('id')->firstOrFail();
    }

    public function test_owner_creates_candidate_but_cannot_silently_approve_or_seal_unchecked_cases(): void
    {
        $c = $this->firm('COMPANYA');
        $sha = str_repeat('a', 64);
        $this->signIn($c, 'warehouse')->post(route('release.acceptance.begin'), [
            'artifact_sha256' => $sha,
            'assets_sha256' => str_repeat('1', 64), 'release_label' => 'Forbidden campaign',
            'scope_note' => 'Nobody but an owner should be able to create releases.',
            'confirm_staging' => 1,
        ])->assertForbidden();
        $this->signIn($c, 'owner')->post(route('release.acceptance.begin'), [
            'artifact_sha256' => 'wrong', 'assets_sha256' => str_repeat('1', 64), 'release_label' => 'Bad candidate',
            'scope_note' => 'Refuse labels that do not refer to valid immutable build files.',
            'confirm_staging' => 1,
        ])->assertSessionHasErrors('artifact_sha256');
        $run = $this->begin($c, $sha);
        $this->assertSame('open', $run->status);
        $this->assertCount(count(ReleaseAcceptanceManager::CHECKS), $run->checks);
        $this->signIn($c, 'owner')->post(route('release.acceptance.seal', $run->id), [
            'seal_note' => 'All independent staging reports are not complete so sealing is forbidden.',
        ])->assertSessionHasErrors('release');
        $this->assertSame('open', $run->fresh()->status);
        $this->assertDatabaseCount('release_acceptance_events', 1);
    }

    public function test_role_scoped_submissions_independent_reviews_and_audit_rejections(): void
    {
        $a = $this->firm('COMPANYA');
        $b = $this->firm('COMPANYB');
        $run = $this->begin($a, str_repeat('b', 64));
        $route = route('release.acceptance.submit', [
            'run' => $run->id, 'code' => 'packing_quality',
        ]);
        $payload = [
            'evidence_reference' => 'QA-PACK-2026-CASE-108',
            'test_notes' => 'Packing and quarantine were tested in staging with independently scanned carton codes.',
        ];
        $this->signIn($a, 'viewer')->get(route('release.acceptance.show', $run->id))
            ->assertForbidden();
        $this->signIn($b, 'owner')->get(route('release.acceptance.show', $run->id))
            ->assertNotFound();
        $this->signIn($a, 'warehouse')->post($route, $payload)
            ->assertSessionHasNoErrors();
        $check = ReleaseAcceptanceCheck::where('run_id', $run->id)
            ->where('check_code', 'packing_quality')->firstOrFail();
        $this->assertSame('submitted', $check->status);
        $this->assertSame($a['users']['warehouse']->id, $check->tested_by);
        $this->post($route, $payload)->assertSessionHasErrors('check');
        $this->signIn($a, 'warehouse')->post(route('release.acceptance.review', [
            'run' => $run->id, 'code' => 'packing_quality', 'action' => 'approve',
        ]), [
            'review_notes' => 'A workshop operator cannot approve her own staging acceptance.',
        ])->assertForbidden();
        $this->signIn($a, 'owner')->post(route('release.acceptance.review', [
            'run' => $run->id, 'code' => 'packing_quality', 'action' => 'reject',
        ]), [
            'review_notes' => 'The carton barcode scanner was not tested after the simulated network outage.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $check->fresh()->status);
        $this->signIn($a, 'warehouse')->post($route, [
            'evidence_reference' => 'QA-PACK-2026-CASE-109',
            'test_notes' => 'Repeated live scanning in isolated staging after outage and verified duplicate labels.',
        ])->assertSessionHasNoErrors();
        $this->signIn($a, 'owner')->post(route('release.acceptance.review', [
            'run' => $run->id, 'code' => 'packing_quality', 'action' => 'approve',
        ]), [
            'review_notes' => 'Reviewed the retest and independently verified the new traceability evidence.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('approved', $check->fresh()->status);
        $this->assertDatabaseCount('release_acceptance_events', 5);
        $this->assertNotNull($run->fresh()->events()->where('action', 'rejected')->first());
        $this->signIn($a, 'warehouse')->post(route('release.acceptance.submit', [
            'run' => $run->id, 'code' => 'finance_controls',
        ]), $payload)->assertForbidden();
    }

    public function test_sealed_campaign_requires_every_distinct_review_and_cannot_be_amended(): void
    {
        $c = $this->firm('COMPANYA');
        $run = $this->begin($c, str_repeat('c', 64));
        $manager = app(ReleaseAcceptanceManager::class);
        foreach (array_keys(ReleaseAcceptanceManager::CHECKS) as $code) {
            $manager->submit($c['business'], $run->id, $code,
                'UAT-'.strtoupper($code).'-REPORT-2026',
                'Staging test and separate independent source evidence recorded for '.$code.'.',
                $c['users']['owner']->id);
            $manager->review($c['business'], $run->id, $code, true,
                'Reviewed test observations, reports and residual risks for '.$code.'.',
                $c['users']['admin']->id);
        }
        $this->assertSame(count(ReleaseAcceptanceManager::CHECKS),
            $run->fresh()->checks()->where('status', 'approved')->count());
        $manager->seal($c['business'], $run->id,
            'Owner reviewed all categories and formally sealed the staging acceptance evidence.',
            $c['users']['owner']->id);
        $this->assertSame('sealed', $run->fresh()->status);
        $this->assertDatabaseCount('release_acceptance_checks',
            count(ReleaseAcceptanceManager::CHECKS));
        $this->signIn($c, 'owner')->post(route('release.acceptance.submit', [
            'run' => $run->id, 'code' => 'sales_orders',
        ]), [
            'evidence_reference' => 'QA-NEW-UNACCEPTED',
            'test_notes' => 'An already sealed signoff cannot be edited or silently changed.',
        ])->assertSessionHasErrors('release');
        $this->signIn($c, 'owner')->get(route('release.acceptance.show', $run->id))
            ->assertOk()->assertSee('Evidence sealed');
    }

    public function test_release_gate_remains_blocked_in_local_even_with_all_human_evidence(): void
    {
        $c = $this->firm('COMPANYA');
        $sha = str_repeat('d', 64);
        $run = $this->begin($c, $sha);
        $manager = app(ReleaseAcceptanceManager::class);
        foreach (array_keys(ReleaseAcceptanceManager::CHECKS) as $code) {
            $manager->submit($c['business'], $run->id, $code,
                'UAT-'.strtoupper($code).'-EXTERNAL-REFERENCE',
                'Operator claims independent staging test evidence for '.$code.'.',
                $c['users']['owner']->id);
            $manager->review($c['business'], $run->id, $code, true,
                'Management reviewed externally supplied report reference for '.$code.'.',
                $c['users']['admin']->id);
        }
        $manager->seal($c['business'], $run->id,
            'Evidence sealed for test only; no real production readiness is implied.',
            $c['users']['owner']->id);
        $file = tempnam(sys_get_temp_dir(), 'expo-artifact-');
        file_put_contents($file, 'synthetic release bytes (test-only)');
        try {
            $realSha = hash_file('sha256', $file);
            config()->set('expo_release.assets_sha256', str_repeat('1', 64));
            config()->set('expo_release.artifact_sha256', $sha);
            config()->set('expo_release.artifact_path', $file);
            $gate = app(ReleaseGate::class)->inspect($c['business'], $sha, false);
            $this->assertFalse($gate['ready']);
            $codes = collect($gate['checks'])->keyBy('code');
            $this->assertSame('ok', $codes['independent_business_acceptance']['status']);
            $this->assertSame('fail', $codes['release_artifact_verified']['status']);
            $this->assertSame('fail', $codes['real_environment_probes_executed']['status']);
            $this->assertSame('fail', $codes['preflight_production_environment']['status']);

            config()->set('expo_release.artifact_sha256', $realSha);
            $gate = app(ReleaseGate::class)->inspect($c['business'], $realSha, false);
            $this->assertFalse($gate['ready']);
            $codes = collect($gate['checks'])->keyBy('code');
            $this->assertSame('ok', $codes['release_artifact_verified']['status']);
            $this->assertSame('fail', $codes['independent_business_acceptance']['status']);
        } finally {
            unlink($file);
        }
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('external_provider_events', 0);
    }

    public function test_read_only_audit_fails_on_corrupt_double_entry_and_passes_after_fix(): void
    {
        $c = $this->firm('COMPANYA');
        $account = FinanceAccount::create([
            'business_id' => $c['business']->id,
            'code' => '1010', 'system_key' => 'cash', 'type' => 'asset',
            'name' => 'Cash', 'active' => true,
        ]);
        $journal = FinanceJournal::create([
            'business_id' => $c['business']->id,
            'reference' => 'QA-JRN-1', 'source_type' => 'qa_reference', 'source_id' => 1,
            'posted_on' => today(), 'currency' => 'UGX', 'base_currency' => 'UGX',
            'rate_to_base' => '1.0000000000', 'description' => 'Deliberately unbalanced isolated audit case',
            'created_by' => $c['users']['owner']->id,
            'approved_by' => $c['users']['admin']->id,
        ]);
        FinanceJournalLine::create([
            'business_id' => $c['business']->id,
            'journal_id' => $journal->id, 'account_id' => $account->id,
            'debit' => '100.00', 'credit' => '0',
            'base_debit' => '100.00', 'base_credit' => '0',
        ]);
        $report = app(ReleaseIntegrityInspector::class)->inspect($c['business']);
        $this->assertFalse($report['ready']);
        $this->assertSame(1, collect($report['checks'])
            ->firstWhere('code', 'double_entry_integrity')['failures']);
        FinanceJournalLine::create([
            'business_id' => $c['business']->id,
            'journal_id' => $journal->id, 'account_id' => $account->id,
            'debit' => '0', 'credit' => '100.00',
            'base_debit' => '0', 'base_credit' => '100.00',
        ]);
        $passed = app(ReleaseIntegrityInspector::class)->inspect($c['business']);
        $this->assertTrue($passed['ready']);
        $this->assertSame(0, $passed['errors']);
    }

    public function test_external_security_report_details_are_not_rendered_to_non_management(): void
    {
        $c = $this->firm('COMPANYA');
        $run = $this->begin($c, str_repeat('f', 64));
        $manager = app(ReleaseAcceptanceManager::class);
        $manager->submit($c['business'], $run->id, 'penetration_test',
            'PEN-EXTERNAL-REPORT-SECRET-ONLY-FOR-MANAGEMENT',
            'Sensitive assessment finding KEYWORD_CONFIDENTIAL should be visible only to owners and admins.',
            $c['users']['owner']->id);
        $manager->review($c['business'], $run->id, 'penetration_test', true,
            'Independent reviewer checked the confidential report and its limited distribution.',
            $c['users']['admin']->id);
        $this->signIn($c, 'logistics')->get(route('release.acceptance.show', $run->id))
            ->assertOk()
            ->assertDontSee('KEYWORD_CONFIDENTIAL')
            ->assertDontSee('PEN-EXTERNAL-REPORT-SECRET-ONLY-FOR-MANAGEMENT')
            ->assertSee('Restricted security, finance or infrastructure review');
        $this->signIn($c, 'owner')->get(route('release.acceptance.show', $run->id))
            ->assertOk()
            ->assertSee('KEYWORD_CONFIDENTIAL');
    }

    public function test_integrity_detects_negative_batch_stock_without_modifying_it(): void
    {
        $c = $this->firm('COMPANYA');
        $product = Product::create([
            'business_id' => $c['business']->id, 'sku' => 'UAT-1',
            'name' => 'Produce sample', 'base_unit' => 'kg',
        ]);
        $batch = StockBatch::create([
            'business_id' => $c['business']->id, 'product_id' => $product->id,
            'batch_code' => 'UAT-BATCH', 'received_on' => today()->toDateString(),
        ]);
        $movement = StockMovement::create([
            'business_id' => $c['business']->id,
            'batch_id' => $batch->id, 'movement_type' => 'packing',
            'quantity' => '-10.000', 'unit' => 'kg', 'source_type' => 'uat_finding',
            'source_id' => 1, 'created_by' => $c['users']['owner']->id,
        ]);
        $bad = app(ReleaseIntegrityInspector::class)->inspect($c['business']);
        $this->assertFalse($bad['ready']);
        $this->assertSame(1, collect($bad['checks'])
            ->firstWhere('code', 'nonnegative_batch_balances')['failures']);
        $this->assertTrue(BigDecimal::of((string) $movement->fresh()->quantity)->isEqualTo('-10.000'));
        StockMovement::create([
            'business_id' => $c['business']->id,
            'batch_id' => $batch->id, 'movement_type' => 'receipt',
            'quantity' => '10.000', 'unit' => 'kg', 'source_type' => 'uat_finding',
            'source_id' => 2, 'created_by' => $c['users']['owner']->id,
        ]);
        $this->assertTrue(app(ReleaseIntegrityInspector::class)
            ->inspect($c['business'])['ready']);
    }

    public function test_shared_erp_release_requires_acceptance_from_every_active_company(): void
    {
        $a = $this->firm('TENANTA');
        $b = $this->firm('TENANTB');
        $sha = str_repeat('9', 64);
        $assets = str_repeat('1', 64);
        config()->set('expo_release.assets_sha256', $assets);
        $manager = app(ReleaseAcceptanceManager::class);

        $seal = function (array $company) use ($sha, $manager) {
            $run = $this->begin($company, $sha);
            foreach (array_keys(ReleaseAcceptanceManager::CHECKS) as $code) {
                $manager->submit($company['business'], $run->id, $code,
                    'VALIDATED-QA-'.$code.'-2026',
                    'Staging acceptance report referenced for independently checked '.$code.'.',
                    $company['users']['owner']->id);
                $manager->review($company['business'], $run->id, $code, true,
                    'Independent manager verified the referenced test result for '.$code.'.',
                    $company['users']['admin']->id);
            }
            $manager->seal($company['business'], $run->id,
                'All twelve source and frontend acceptance categories were independently reviewed.',
                $company['users']['owner']->id);

            return $run;
        };

        $first = $seal($a);
        $initial = collect(app(ReleaseGate::class)->inspect($a['business'], $sha, false)['checks'])
            ->keyBy('code');
        $this->assertSame('ok', $initial['independent_business_acceptance']['status']);
        $this->assertSame('fail', $initial['all_active_tenants_accepted']['status']);

        $second = $seal($b);
        $covered = collect(app(ReleaseGate::class)->inspect($a['business'], $sha, false)['checks'])
            ->keyBy('code');
        $this->assertSame('ok', $covered['all_active_tenants_accepted']['status']);
        $this->assertSame('ok', $covered['all_active_tenants_integrity']['status']);

        // Opening a more recent revision to the same candidate invalidates
        // the earlier sealed campaign until retested and sealed again.
        $this->begin($b, $sha);
        $superseded = collect(app(ReleaseGate::class)->inspect($a['business'], $sha, false)['checks'])
            ->keyBy('code');
        $this->assertSame('fail', $superseded['all_active_tenants_accepted']['status']);
        $this->assertSame('sealed', $first->fresh()->status);
        $this->assertSame('sealed', $second->fresh()->status);
    }

    public function test_build_fingerprint_detects_tampered_and_executable_assets(): void
    {
        $root = sys_get_temp_dir().'/expo-public-build-'.(string) Str::uuid();
        mkdir($root.'/assets', 0700, true);
        try {
            file_put_contents($root.'/assets/app-sample.js', 'console.log("source code");');
            file_put_contents($root.'/assets/app-sample.css', 'body{margin:0}');
            file_put_contents($root.'/manifest.json', json_encode([
                'resources/js/app.js' => [
                    'file' => 'assets/app-sample.js', 'isEntry' => true,
                ],
                'resources/css/app.css' => [
                    'file' => 'assets/app-sample.css', 'isEntry' => true,
                ],
            ], JSON_THROW_ON_ERROR));
            $verifier = app(ReleaseBuildVerifier::class);
            $original = $verifier->inspect($root);
            $this->assertTrue($original['ok']);
            $this->assertSame(3, $original['files']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $original['sha256']);

            file_put_contents($root.'/assets/app-sample.js', 'console.log("tampered code");');
            $changed = $verifier->inspect($root);
            $this->assertTrue($changed['ok']);
            $this->assertNotSame($original['sha256'], $changed['sha256']);

            file_put_contents($root.'/assets/unwanted.php', 'No public PHP files permitted.');
            $this->assertFalse($verifier->inspect($root)['ok']);
            unlink($root.'/assets/unwanted.php');

            unlink($root.'/assets/app-sample.css');
            $this->assertFalse($verifier->inspect($root)['ok']);
        } finally {
            foreach (glob($root.'/assets/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root.'/assets')) {
                rmdir($root.'/assets');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function test_immutable_source_archive_verifies_then_blocks_any_changed_or_extra_php(): void
    {
        $directory = sys_get_temp_dir().'/expo-release-files-'.(string) Str::uuid();
        $installed = $directory.'/installed';
        $code = $installed.'/backend/app';
        $public = $installed.'/backend/public/assets';
        mkdir($code, 0700, true);
        mkdir($public, 0700, true);
        $tar = $directory.'/candidate.tar';
        try {
            $archive = new \PharData($tar);
            for ($i = 0; $i < 55; $i++) {
                $name = 'Source'.sprintf('%03d', $i).'.php';
                $file = $code.'/'.$name;
                file_put_contents($file, "<?php\n// isolated test file ".$i."\n");
                $archive->addFile($file, 'boosted-export-erp/backend/app/'.$name);
            }
            $script = $public.'/console-ui.js';
            file_put_contents($script, 'console.log("archive matches approved UI");');
            $archive->addFile($script, 'boosted-export-erp/backend/public/assets/console-ui.js');
            unset($archive);
            // Create a standard gzip stream rather than PharData::compress:
            // some macOS libphar builds read that compressed tar as zero bytes.
            $gz = $tar.'.gz';
            file_put_contents($gz, gzencode((string) file_get_contents($tar), 9));
            $verifier = app(ReleaseArtifactVerifier::class);
            $before = $verifier->compare($gz, $installed);
            $this->assertTrue($before['ok'], json_encode($before));
            $this->assertSame(56, $before['compared']);
            $this->assertSame(0, $before['missing_or_changed']);
            $this->assertSame(0, $before['unexpected_source']);

            file_put_contents($script, 'console.log("unapproved UI change");');
            $changed = $verifier->compare($gz, $installed);
            $this->assertFalse($changed['ok']);
            $this->assertSame(1, $changed['missing_or_changed']);

            file_put_contents($script, 'console.log("archive matches approved UI");');
            file_put_contents($code.'/Injected.php', "<?php\n// unapproved source\n");
            $unexpected = $verifier->compare($gz, $installed);
            $this->assertFalse($unexpected['ok']);
            $this->assertSame(1, $unexpected['unexpected_source']);
        } finally {
            if (is_dir($directory)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $node) {
                    if ($node->isDir()) {
                        rmdir($node->getPathname());
                    } else {
                        unlink($node->getPathname());
                    }
                }
                rmdir($directory);
            }
        }
    }

    public function test_release_cli_is_read_only_and_refuses_local_production_authorisation(): void
    {
        $c = $this->firm('COMPANYA');
        $this->artisan('expo:data-audit',['business' => $c['business']->id])
            ->assertExitCode(0);
        $this->artisan('expo:release-gate',[
            'business' => $c['business']->id, '--sha' => str_repeat('e',64),
        ])->assertExitCode(1);
        $this->assertDatabaseCount('release_acceptance_runs',0);
        $this->assertDatabaseCount('finance_posting_requests',0);
    }
}
