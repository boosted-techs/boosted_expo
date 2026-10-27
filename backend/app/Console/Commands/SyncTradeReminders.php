<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\FinanceTradeControls;
use App\Services\PlatformTenancy;
use Illuminate\Console\Command;

class SyncTradeReminders extends Command
{
    protected $signature = 'expo:trade-reminders {--business= : Limit to one business ID}';

    protected $description = 'Refresh internal due-payment cases without sending external messages';

    public function handle(FinanceTradeControls $controls, PlatformTenancy $tenancy): int
    {
        $id = $this->option('business');
        if ($id !== null && ! ctype_digit((string) $id)) {
            $this->error('Choose a numeric business ID.');

            return self::FAILURE;
        }$count = 0;
        $skipped = 0;
        foreach (Business::query()->where('is_active', true)->when($id, fn ($q) => $q->whereKey($id))->orderBy('id')->cursor() as $business) {
            $state = $tenancy->state($business);
            if (! $state['can_write'] || (isset($state['terms']['modules']) && ! in_array('finance', $state['terms']['modules'], true))) {
                $skipped++;

                continue;
            }$count += $controls->remind($business);
        }
        $this->info($count.' internal reminders changed; '.$skipped.' restricted businesses skipped. No external messages sent.');

        return self::SUCCESS;
    }
}
