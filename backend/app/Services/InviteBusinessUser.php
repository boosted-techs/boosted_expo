<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteBusinessUser
{
    public function send(Business $business, string $email, string $role, ?int $invitedBy = null, string $purpose = 'membership', ?int $replaces = null): BusinessInvitation
    {
        $email = strtolower(trim($email));
        $plainToken = Str::random(64);
        [$invitation,$delivery] = DB::transaction(function () use ($business, $email, $role, $invitedBy, $purpose, $replaces, $plainToken) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_unless($business->is_active, 404);
            if ($replaces) {
                BusinessMembership::query()->where('business_id', $business->id)->where('role', 'owner')->where('active', true)->findOrFail($replaces);
                BusinessInvitation::query()->where('business_id', $business->id)->where('replaces_membership_id', $replaces)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            app(PlatformUsage::class)->assertSeat($business, $email, null, $replaces);
            BusinessInvitation::query()->where('business_id', $business->id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $invitation = BusinessInvitation::create(['business_id' => $business->id, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $plainToken), 'invited_by' => $invitedBy, 'expires_at' => now()->addDays(3), 'purpose' => $purpose, 'replaces_membership_id' => $replaces]);
            $delivery = app(PlatformMail::class)->prepare($business->id, 'invitation', $invitation->id, $email, ['token' => $plainToken], 'invitation:'.$invitation->id);
            app(PlatformAccess::class)->audit($business->id, $invitedBy, 'owner_or_staff.invited', 'Invitation purpose: '.$purpose, [], ['invitation_id' => $invitation->id, 'email' => $email, 'role' => $role]);

            return [$invitation, $delivery];
        }, 3);
        app(PlatformMail::class)->deliver($delivery);

        return $invitation;
    }
}
