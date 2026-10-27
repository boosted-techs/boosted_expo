<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Services\PlatformAccess;
use App\Services\PlatformUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    private function invitation(string $token): BusinessInvitation
    {
        return BusinessInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereHas('business', fn ($q) => $q->where('is_active', true))
            ->with('business')
            ->firstOrFail();
    }

    public function show(Request $request, string $token): View
    {
        $invitation = $this->invitation($token);
        $existing = User::query()->where('email', $invitation->email)->exists();

        if ($existing && ! $request->user()) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return view('invitations.accept', compact('invitation', 'existing', 'token'));
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        $existing = User::query()->where('email', $invitation->email)->first();
        $data = [];

        if ($existing) {
            if (! $request->user() || $request->user()->id !== $existing->id) {
                throw ValidationException::withMessages(['email' => 'Sign in with the invited email address first.']);
            }
        } else {
            abort_if($request->user(), 403, 'Sign out before accepting an invitation for a new account.');
            $data = $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:140'],
                'password' => ['required', 'confirmed', Password::min(12)],
            ]);
        }

        // Lock the invitation so one link cannot be redeemed twice.
        $user = DB::transaction(function () use ($invitation, $existing, $data) {
            $business = Business::query()->lockForUpdate()->findOrFail($invitation->business_id);
            $locked = BusinessInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($locked->accepted_at === null && $locked->revoked_at === null && $locked->expires_at->isFuture() && $locked->business()->where('is_active', true)->exists(), 404);
            if ($locked->purpose === 'owner_transfer') {
                abort_unless(BusinessMembership::query()->where('business_id', $business->id)->whereKey($locked->replaces_membership_id)->where('active', true)->where('role', 'owner')->exists(), 409, 'The previous owner access changed. Ask support to review this transfer again.');
            }
            app(PlatformUsage::class)->assertSeat($business, $locked->email, $locked->id);
            $user = $existing ?? User::create([
                'name' => $data['name'],
                'email' => $locked->email,
                'password' => $data['password'],
                'email_verified_at' => now(), // Proof is possession of the emailed invitation.
            ]);
            BusinessMembership::query()->updateOrCreate(
                ['business_id' => $locked->business_id, 'user_id' => $user->id],
                ['role' => $locked->role, 'active' => true],
            );
            $locked->forceFill(['accepted_at' => now()])->save();
            if ($locked->purpose === 'owner_transfer' && $locked->replaces_membership_id) {
                $previous = BusinessMembership::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($locked->replaces_membership_id);
                if ($previous->user_id !== $user->id) {
                    $previous->update(['active' => false]);
                }
            }
            if ($locked->role === 'owner') {
                $business->update(['owner_contact_email' => $locked->email]);
            }
            app(PlatformAccess::class)->audit($business->id, $user->id, 'invitation.accepted', 'Invited user proved email possession and accepted business access.', [], ['invitation_id' => $locked->id, 'role' => $locked->role]);

            return $user;
        });

        if (! Auth::check()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $request->session()->put('active_business_id', $invitation->business_id);

        return redirect()->route('workspace')->with('status', 'Your company workspace is ready.');
    }
}
