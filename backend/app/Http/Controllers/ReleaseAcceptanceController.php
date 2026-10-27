<?php

namespace App\Http\Controllers;

use App\Models\ReleaseAcceptanceRun;
use App\Services\ReleaseAcceptanceManager;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReleaseAcceptanceController extends Controller
{
    private function allowed(Request $request):void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'release.acceptance.view'),403);
    }

    private function own(Request $request,int $run):ReleaseAcceptanceRun
    {
        return ReleaseAcceptanceRun::query()
            ->where('business_id',$request->attributes->get('activeBusiness')->id)
            ->findOrFail($run);
    }

    public function index(Request $request):View
    {
        $this->allowed($request);
        $runs=ReleaseAcceptanceRun::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->withCount(['checks as accepted_checks_count'=>fn($q)=>$q->where('status','approved')])
            ->orderByDesc('id')->paginate(20);
        $isOwner=$request->attributes->get('membership')->role==='owner';
        return view('release.index',compact('runs','isOwner'));
    }

    public function begin(Request $request,ReleaseAcceptanceManager $manager):RedirectResponse
    {
        $this->allowed($request);
        $data=$request->validate([
            'artifact_sha256'=>['required','regex:/^[a-fA-F0-9]{64}$/D'],
            'assets_sha256'=>['required','regex:/^[a-fA-F0-9]{64}$/D'],
            'release_label'=>['required','string','min:4','max:100'],
            'scope_note'=>['required','string','min:30','max:3000'],
            'confirm_staging'=>['accepted'],
        ]);
        $run=$manager->begin($request->attributes->get('activeBusiness'),
            $data,$request->user()->id);
        return redirect()->route('release.acceptance.show',$run->id)
            ->with('status','Acceptance campaign opened for this exact release artifact. Human review does not certify production readiness.');
    }

    public function show(Request $request,int $run):View
    {
        $this->allowed($request);
        $run=$this->own($request,$run)->load([
            'checks'=>fn($q)=>$q->orderBy('id'),
            'events'=>fn($q)=>$q->orderByDesc('id')->limit(65),
        ]);
        $membership=$request->attributes->get('membership');
        $reviewer=in_array($membership->role,['owner','admin'],true);
        $owner=$membership->role==='owner';
        $canSubmit=[];
        foreach(ReleaseAcceptanceManager::CHECKS as $code=>$details){
            $canSubmit[$code]=$details['permission']
                ?app(BusinessPermissions::class)->allows($membership,$details['permission'])
                :$reviewer;
        }
        $allApproved=$run->checks->count()===count(ReleaseAcceptanceManager::CHECKS)
            && $run->checks->every(fn($c)=>$c->status==='approved');
        $labels=ReleaseAcceptanceManager::CHECKS;
        return view('release.show',compact('run','reviewer','owner',
            'canSubmit','allApproved','labels'));
    }

    public function submit(Request $request,int $run,string $code,
        ReleaseAcceptanceManager $manager):RedirectResponse
    {
        $this->allowed($request);
        $run=$this->own($request,$run);
        $data=$request->validate([
            'evidence_reference'=>['required','string','min:12','max:500'],
            'test_notes'=>['required','string','min:30','max:3000'],
        ]);
        $manager->submit($request->attributes->get('activeBusiness'),
            $run->id,$code,$data['evidence_reference'],
            $data['test_notes'],$request->user()->id);
        return back()->with('status','Staging acceptance evidence submitted for separate review.');
    }

    public function review(Request $request,int $run,string $code,string $action,
        ReleaseAcceptanceManager $manager):RedirectResponse
    {
        $this->allowed($request);
        abort_unless(in_array($action,['approve','reject'],true),404);
        $run=$this->own($request,$run);
        $data=$request->validate(['review_notes'=>['required','string','min:30','max:3000']]);
        $manager->review($request->attributes->get('activeBusiness'),
            $run->id,$code,$action==='approve',
            $data['review_notes'],$request->user()->id);
        return back()->with('status',$action==='approve'
            ?'Evidence independently accepted for this release candidate.'
            :'Test rejected with a review reason; a new attempt must preserve the history.');
    }

    public function seal(Request $request,int $run,ReleaseAcceptanceManager $manager):RedirectResponse
    {
        $this->allowed($request);
        $run=$this->own($request,$run);
        $data=$request->validate(['seal_note'=>['required','string','min:30','max:3000']]);
        $manager->seal($request->attributes->get('activeBusiness'),
            $run->id,$data['seal_note'],$request->user()->id);
        return back()->with('status',
            'Release acceptance evidence sealed. Live preflight, production restoration and deployment approvals remain separate mandatory gates.');
    }
}
