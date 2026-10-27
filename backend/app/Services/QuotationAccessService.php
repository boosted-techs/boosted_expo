<?php

namespace App\Services;

use App\Models\Business;
use App\Models\QuotationAccess;
use App\Models\SalesQuotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotationAccessService
{
    public function create(SalesQuotation $quote, string $email, int $actor): string
    {
        return DB::transaction(function () use ($quote, $email, $actor) {
            app(OrderOperations::class)->lockForWork($quote->business_id, 'sales');
            $quote = SalesQuotation::query()->where('business_id', $quote->business_id)->lockForUpdate()->findOrFail($quote->id);
            $this->assertCurrent($quote);
            QuotationAccess::query()->where('business_id', $quote->business_id)->where('sales_quotation_id', $quote->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = Str::random(64);
            $expires = now()->addDays(14);
            if ($quote->valid_until && Carbon::parse($quote->valid_until->toDateString(), Business::query()->findOrFail($quote->business_id)->timezone)->endOfDay()->utc()->isBefore($expires)) {
                $expires = Carbon::parse($quote->valid_until->toDateString(), Business::query()->findOrFail($quote->business_id)->timezone)->endOfDay()->utc();
            }
            QuotationAccess::create(['business_id' => $quote->business_id, 'sales_quotation_id' => $quote->id, 'token_hash' => hash('sha256', $token), 'recipient_email' => $email, 'expires_at' => $expires, 'created_by' => $actor]);

            return $token;
        }, 3);
    }

    private function assertCurrent(SalesQuotation $quote): void
    {
        abort_unless($quote->status === 'issued' && $quote->salesRequest->quotations()->max('revision') === $quote->revision, 410, 'This quotation has been replaced or already decided.');
        abort_if($quote->valid_until && Carbon::parse($quote->valid_until->toDateString(), Business::query()->findOrFail($quote->business_id)->timezone)->endOfDay()->utc()->isPast(), 410, 'This quotation has expired.');
    }

    public function resolve(string $token): array
    {
        $access = QuotationAccess::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $business = Business::query()->where('is_active', true)->findOrFail($access->business_id);
        abort_if($access->revoked_at || $access->expires_at->isPast(), 410, 'This quotation link has expired or been withdrawn.');
        $quote = SalesQuotation::query()->where('business_id', $business->id)->findOrFail($access->sales_quotation_id);
        abort_unless($quote->salesRequest->customer?->active, 404);
        if (! $access->decision) {
            $this->assertCurrent($quote);
        }

        return compact('access', 'business', 'quote');
    }

    public function decide(string $token, array $data): void
    {
        $resolved = $this->resolve($token);
        DB::transaction(function () use ($resolved, $token, $data) {
            app(OrderOperations::class)->lockForWork($resolved['business']->id, 'sales');
            $access = QuotationAccess::query()->where('business_id', $resolved['business']->id)->lockForUpdate()->findOrFail($resolved['access']->id);
            $this->resolve($token);
            if ($access->decision) {
                abort_unless($access->decision === $data['decision'] && $access->buyer_name === $data['buyer_name'] && $access->buyer_reference === $data['buyer_reference'], 409, 'A decision has already been recorded for this link.');

                return;
            }
            $quote = SalesQuotation::query()->where('business_id', $resolved['business']->id)->lockForUpdate()->findOrFail($access->sales_quotation_id);
            $this->assertCurrent($quote);
            if ($data['decision'] === 'accepted') {
                app(SalesWorkflow::class)->confirm($quote, 'Accepted through buyer link #'.$access->id, null);
            } else {
                $quote->update(['status' => 'rejected']);
                $quote->salesRequest->update(['status' => 'requested']);
            }
            $access->update([...$data, 'decided_at' => now()]);
        }, 3);
    }
}
