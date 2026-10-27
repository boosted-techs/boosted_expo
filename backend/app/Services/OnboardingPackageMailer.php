<?php

namespace App\Services;

use App\Mail\BoostedOnePackagesMail;
use App\Models\BillingPlan;
use App\Models\OnboardingRequest;
use Illuminate\Support\Facades\Mail;

class OnboardingPackageMailer
{
    /**
     * Send the approved, public package catalogue. If no plans are yet
     * configured, send a clearly indicative introduction instead of
     * inventing prices. Private/retired packages must never be mailed.
     */
    public function send(OnboardingRequest $application): void
    {
        $plans = BillingPlan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('id')
            ->get([
                'name', 'description',
                'monthly_price_ugx', 'quarterly_price_ugx', 'annual_price_ugx',
            ]);

        $indicative = $plans->isEmpty();

        $packages = $indicative ? [
            [
                'name' => 'Starter',
                'description' => 'A simpler starting point for everyday purchasing, inventory and customer records.',
                'monthly_price_ugx' => null,
                'quarterly_price_ugx' => null,
                'annual_price_ugx' => null,
            ],
            [
                'name' => 'Business',
                'description' => 'For growing exporters and businesses connecting sourcing, packing, shipments and internal controls.',
                'monthly_price_ugx' => null,
                'quarterly_price_ugx' => null,
                'annual_price_ugx' => null,
            ],
            [
                'name' => 'Enterprise',
                'description' => 'For complex operations requiring more detailed roles, reviews and tailored configuration.',
                'monthly_price_ugx' => null,
                'quarterly_price_ugx' => null,
                'annual_price_ugx' => null,
            ],
        ] : $plans->map(static fn (BillingPlan $plan): array => [
            'name' => $plan->name,
            'description' => $plan->description,
            'monthly_price_ugx' => $plan->monthly_price_ugx,
            'quarterly_price_ugx' => $plan->quarterly_price_ugx,
            'annual_price_ugx' => $plan->annual_price_ugx,
        ])->all();

        Mail::to($application->contact_email)->send(
            new BoostedOnePackagesMail(
                $application->contact_name,
                $application->business_name,
                $packages,
                $indicative,
            )
        );

        // The mail transport has accepted the message; inbox delivery is not
        // independently guaranteed by SMTP submission.
        $application->forceFill(['packages_sent_at' => now()])->save();
    }
}
