<?php

namespace App\Services;

use App\Mail\BusinessInvitationMail;
use App\Models\Business;
use App\Models\BusinessInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteBusinessUser
{
    public function send(Business $business, string $email, string $role, ?int $invitedBy = null): BusinessInvitation
    {
        $email = strtolower(trim($email));
        // Superseded links stop working immediately.
        BusinessInvitation::query()
            ->where('business_id', $business->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        $plainToken = Str::random(64);
        $invitation = BusinessInvitation::create([
            'business_id' => $business->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by' => $invitedBy,
            'expires_at' => now()->addDays(3),
        ]);

        Mail::to($email)->send(new BusinessInvitationMail($invitation, $plainToken));

        return $invitation;
    }
}