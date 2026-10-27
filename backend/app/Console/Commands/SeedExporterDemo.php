<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Support\ExporterDemoContext;
use Database\Seeders\ExporterDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SeedExporterDemo extends Command
{
    protected $signature = 'expo:demo-seed';

    protected $description = 'Add a connected, local-only exporter demonstration without replacing existing records or sending mail.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo seeding is available only in local/testing environments.');

            return self::FAILURE;
        }
        $demo = app(ExporterDemoContext::class);
        app()->instance(ExporterDemoContext::class, $demo);
        try {
            app(ExporterDemoSeeder::class)->run($demo);
        } catch (\Throwable $e) {
            $this->error('Demo stopped at '.$demo->stage.': '.$e->getMessage());
            if ($e instanceof ValidationException) {
                $this->line(json_encode($e->errors()));
            }

            return self::FAILURE;
        }
        $this->info($demo->reused ? 'Existing demo reused; passwords and business records were not reset.' : 'Connected demo created. No external messages or money transfers were sent.');
        if ($demo->credentials) {
            $this->table(['Role', 'Email'], array_map(fn ($role, $email) => [$role, $email], array_keys($demo->credentials), array_values($demo->credentials)));
            $this->line('Initial password for these new demo accounts: '.$demo->password);
        }
        $coverage = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $model = new $class;
            $table = $model->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }$q = $model->newQuery();
            if (Schema::hasColumn($table, 'business_id')) {
                $q->where('business_id', $demo->business->id);
            } elseif ($class === Business::class) {
                $q->where('code', 'like', 'DEMO-%');
            } elseif ($class === User::class) {
                $q->where('email', 'like', '%@demo.boosted.test');
            }$coverage[class_basename($class)] = $q->count();
        }
        $path = 'demo/coverage-'.$demo->business->id.'.json';
        if ($demo->reused && Storage::disk('local')->exists($path)) {
            $previous = json_decode(Storage::disk('local')->get($path), true);
            $demo->links = $previous['private_demo_links'] ?? [];
        }
        $report = ['business_id' => $demo->business->id, 'business' => $demo->business->name, 'created_at' => now()->toIso8601String(), 'model_counts' => $coverage, 'private_demo_links' => $demo->links, 'note' => 'Synthetic training records. Hardware, bank/authority and release evidence are explicitly illustrative.'];
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('Model coverage: '.count(array_filter($coverage)).' / '.count($coverage).'. Report: '.Storage::disk('local')->path($path));
        $missing = array_keys(array_filter($coverage, fn ($n) => $n === 0));
        if ($missing) {
            $this->warn('Models still without an example: '.implode(', ', $missing));
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
