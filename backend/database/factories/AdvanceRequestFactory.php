<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AdvanceRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'ADV-'.Str::upper((string) Str::ulid()), 'currency' => 'UGX', 'amount' => '100000', 'needed_on' => '2026-10-27', 'accountable_on' => '2026-10-28', 'reason' => 'Verified market-buying plan and cash accountability request.', 'status' => 'requested', 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'factory request')];
    }
}
