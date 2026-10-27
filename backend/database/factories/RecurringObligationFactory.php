<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringObligationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Packhouse rent', 'currency' => 'UGX', 'amount' => '500000', 'category' => 'rent', 'next_due_on' => '2026-10-27', 'interval_months' => 1, 'active' => true, 'terms' => 'Signed monthly rental agreement; verify each actual supplier bill.'];
    }
}
