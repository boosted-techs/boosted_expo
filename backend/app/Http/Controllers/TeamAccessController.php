<?php
namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\TeamMembershipEvent;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamAccessController extends Controller
{
    public function update(Request $request,int $member):RedirectResponse
    {
        $actorRole=$request->attributes->get('membership')->role;
        abort_unless(in_array($actorRole,['owner','admin'],true),403);
        $data=$request->validate([
            'role'=>['required',Rule::in(BusinessPermissions::INVITABLE_ROLES)],
            'active'=>['required','boolean'],
            'reason'=>['required','string','min:10','max:500'],
        ]);
        $business=$request->attributes->get('activeBusiness');
        DB::transaction(function()use($business,$member,$request,$actorRole,$data){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $target=BusinessMembership::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($member);
            abort_unless($target->user_id!==$request->user()->id,403,
                'You cannot edit or suspend your own membership.');
            abort_unless($target->role!=='owner',403,
                'An owner cannot be reassigned or suspended through this team tool.');
            if($actorRole==='admin'){
                abort_unless($target->role!=='admin' && $data['role']!=='admin',403,
                    'Only a company owner may manage administrator access.');
            }
            $newActive=(bool)$data['active'];
            if($target->role===$data['role'] && (bool)$target->active===$newActive){
                throw ValidationException::withMessages(['role'=>
                    'No team access change has been requested.']);
            }
            TeamMembershipEvent::create([
                'business_id'=>$business->id,'membership_id'=>$target->id,
                'actor_id'=>$request->user()->id,'action'=>'access_updated',
                'previous_role'=>$target->role,'new_role'=>$data['role'],
                'previous_active'=>$target->active,'new_active'=>$newActive,
                'reason'=>$data['reason'],'created_at'=>now(),
            ]);
            $target->update(['role'=>$data['role'],'active'=>$newActive]);
        },3);
        return back()->with('status',
            'Team access updated and recorded in the audit log. Suspended staff lose company access immediately.');
    }
}
