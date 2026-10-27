<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CashCustodyFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Float '.fake()->unique()->numerify('####'), 'currency' => 'UGX', 'authorised_float' => '100000', 'active' => true];
    }
}
