<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BoostedOneBillingCycleCommand extends Command
{
    protected $signature = 'boostedone:billing-cycle {--dry-run}';

    protected $description = 'Reconcile renewals and record subscription reminders through the platform review service';

    public function handle(): int
    {
        return $this->call('platform:review', $this->option('dry-run') ? ['--dry-run' => true] : ['--notify' => true]);
    }
}
