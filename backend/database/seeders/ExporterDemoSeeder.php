<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\PlatformAuditEvent;
use App\Services\PlatformAccess;
use App\Support\ExporterDemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ExporterDemoSeeder extends Seeder
{
    public function run(ExporterDemoContext $demo): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Demo accounts can only be seeded in local or testing environments.');
        app()->instance(ExporterDemoContext::class, $demo);
        if ($b = Business::where('code', 'DEMO-EXPORT')->first()) {
            abort_unless(PlatformAuditEvent::where('business_id', $b->id)->where('action', 'demo.seed.completed')->exists(), 422, 'The demo code already belongs to a business without a completed demo manifest. No existing data was changed.');
            $demo->business = $b;
            $demo->reused = true;
            $demo->credentials = [];

            return;
        }
        $mailer = Mail::getFacadeRoot();
        $default = config('mail.default');
        Mail::fake();
        config(['mail.default' => 'log']);
        try {
            DB::transaction(function () use ($demo) {
                foreach ([ExporterDemoPlatformSeeder::class, ExporterDemoOperationsSeeder::class, ExporterDemoPeopleSeeder::class, ExporterDemoFinanceSeeder::class, ExporterDemoExceptionsSeeder::class, ExporterDemoBankSeeder::class, ExporterDemoEvidenceSeeder::class] as $class) {
                    $demo->stage = class_basename($class);
                    app($class)->run($demo);
                    if ($class === ExporterDemoPlatformSeeder::class) {
                        $demo->stage = 'opening cutover';
                        app(ExporterDemoFinanceSeeder::class)->opening($demo);
                    }
                }
                app(PlatformAccess::class)->audit($demo->business->id, $demo->id('superadmin'), 'demo.seed.completed', $demo->note('Connected exporter training scenario created through normal business services.'), [], ['scenario_version' => 1, 'record_ids' => array_map(fn ($r) => $r->id, $demo->records), 'accounts' => array_keys($demo->users)]);
            });
        } catch (\Throwable $e) {
            foreach ($demo->files as $path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        } finally {
            Mail::swap($mailer);
            config(['mail.default' => $default]);
        }
    }
}
