<?php

namespace App\Services;

use App\Mail\BoostedOneBillingNoticeMail;
use App\Mail\BoostedOneInvoiceMail;
use App\Mail\BusinessInvitationMail;
use App\Mail\PlatformNoticeMail;
use App\Models\BillingInvoice;
use App\Models\BusinessInvitation;
use App\Models\PlatformDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PlatformMail
{
    public function prepare(int $business, string $kind, ?int $source, string $recipient, array $payload, string $key): PlatformDelivery
    {
        return PlatformDelivery::query()->firstOrCreate(['deduplication_key' => $key], ['business_id' => $business, 'kind' => $kind, 'source_id' => $source, 'recipient' => $recipient, 'payload' => $payload]);
    }

    public function deliver(PlatformDelivery $delivery, bool $manual = false): bool
    {
        $claimed = DB::transaction(function () use ($delivery, $manual) {
            $d = PlatformDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if (in_array($d->status, ['sent', 'logged', 'cancelled'], true) || $d->status === 'processing' || (! $manual && $d->attempts >= 5)) {
                return false;
            }
            $d->update(['status' => 'processing', 'attempts' => $d->attempts + 1, 'attempted_at' => now(), 'lease_until' => now()->addMinutes(5), 'failure_note' => null]);

            return true;
        }, 3);
        if (! $claimed) {
            return false;
        }
        $d = $delivery->fresh();
        try {
            $payload = $d->payload;
            if ($d->kind === 'invitation') {
                $invite = BusinessInvitation::query()->where('business_id', $d->business_id)->findOrFail($d->source_id);
                if ($invite->revoked_at || $invite->accepted_at || ! $invite->expires_at->isFuture()) {
                    $d->update(['status' => 'cancelled', 'lease_until' => null]);

                    return false;
                }
                $mail = new BusinessInvitationMail($invite, $payload['token']);
            } elseif (in_array($d->kind, ['invoice', 'invoice_reminder'], true)) {
                $invoice = BillingInvoice::query()->where('business_id', $d->business_id)->findOrFail($d->source_id);
                if ($invoice->status === 'void' || ($d->kind === 'invoice_reminder' && $invoice->remaining() === 0)) {
                    $d->update(['status' => 'cancelled', 'lease_until' => null]);

                    return false;
                }
                $mail = $d->kind === 'invoice' ? new BoostedOneInvoiceMail($invoice) : new BoostedOneBillingNoticeMail($invoice, $payload['message']);
            } else {
                $mail = new PlatformNoticeMail($payload['title'], $payload['message']);
            }
            Mail::to($d->recipient)->send($mail);
            $d->update(['status' => config('mail.default') === 'log' ? 'logged' : 'sent', 'sent_at' => now(), 'lease_until' => null]);
            if ($d->kind === 'invoice') {
                $invoice->update(['sent_at' => now()]);
            }

            return true;
        } catch (\Throwable $e) {
            report($e);
            $d->update(['status' => 'failed', 'lease_until' => null, 'failure_note' => 'Delivery failed. Check the configured mail transport and retry; no recipient credentials are exposed.']);

            return false;
        }
    }
}
