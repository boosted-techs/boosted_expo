<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessAssetFactory extends Factory
{
    public function definition(): array
    {
        return ['asset_code' => 'AST-'.fake()->unique()->numerify('######'), 'name' => 'Packing scale', 'category' => 'equipment', 'ownership' => 'owned', 'location' => 'Main packhouse', 'condition' => 'good', 'operating_status' => 'available', 'useful_life_months' => 36, 'residual_base' => '0'];
    }
}
