<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\TeamMembershipEvent;
use App\Services\InviteBusinessUser;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request, BusinessPermissions $access): View
    {
        abort_unless($access->allows($request->attributes->get('membership'), 'team.view'), 403);
        $business = $request->attributes->get('activeBusiness');
        $members = BusinessMembership::query()->where('business_id', $business->id)->with('user')->get();
        $pending = $business->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get();
        $roles = array_values(array_filter(
            BusinessPermissions::INVITABLE_ROLES,
            fn (string $role) => $access->canInviteRole($request->attributes->get('membership'), $role),
        ));

        $canManage = in_array($request->attributes->get('membership')->role, ['owner', 'admin'], true);
        $actorRole = $request->attributes->get('membership')->role;
        $audit = TeamMembershipEvent::query()->where('business_id', $business->id)
            ->latest('id')->limit(20)->get();

        return view('team.index', compact('business', 'members', 'pending', 'roles',
            'canManage', 'actorRole', 'audit'));
    }

    public function invite(Request $request, BusinessPermissions $access, InviteBusinessUser $invites): RedirectResponse
    {
        $membership = $request->attributes->get('membership');
        abort_unless($access->allows($membership, 'team.invite'), 403);
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(BusinessPermissions::INVITABLE_ROLES)],
        ]);
        abort_unless($access->canInviteRole($membership, $input['role']), 403);

        $email = strtolower(trim($input['email']));
        $business = $request->attributes->get('activeBusiness');
        if ($business->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->where('active', true)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user already belongs to the business.']);
        }

        $invites->send($business, $email, $input['role'], $request->user()->id);

        $message = 'Invitation saved for '.$email.'. Delivery attempts are recorded; ask your platform operator to review a failed delivery or resend the invitation.';

        return back()->with('status', $message);
    }
}
