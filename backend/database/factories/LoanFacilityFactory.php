<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class LoanFacilityFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'LOAN-'.fake()->unique()->numerify('######'), 'lender_name' => 'Business lender', 'lender_kind' => 'external', 'currency' => 'UGX', 'limit_amount' => '1000000', 'revolving' => false, 'starts_on' => '2026-01-01', 'matures_on' => '2027-12-31', 'agreement_reference' => 'Signed facility agreement', 'terms' => 'Interest and fees follow the signed agreement; entered explicitly.', 'status' => 'active'];
    }
}
