<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ReleaseAcceptanceCheck;
use App\Models\ReleaseAcceptanceEvent;
use App\Models\ReleaseAcceptanceRun;
use App\Support\BusinessPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseAcceptanceManager
{
    // Human acceptance is intentionally distinct from technical regression
    // and independent issuer/provider proof. Record exact external evidence
    // references; never store private credentials or actual documents here.
    public const CHECKS = [
        'sales_orders'=>[
            'label'=>'Sales, customers and invoices',
            'permission'=>'sales.manage',
            'guide'=>'Staging buyer quotation, confirmed order, partial shipment and immutable customer invoice checks.',
        ],
        'purchases_receiving'=>[
            'label'=>'Purchasing and goods receiving',
            'permission'=>'receipts.post',
            'guide'=>'Staging supplier RFQ, purchases, accepted/rejected deliveries, stock sourcing and tenant separation.',
        ],
        'packing_quality'=>[
            'label'=>'Packing, quality and traceability',
            'permission'=>'packing.manage',
            'guide'=>'Staging packing, quality holds, carton labels, waste and duplicate allocation prevention.',
        ],
        'shipment_documents'=>[
            'label'=>'Shipment and regulatory controls',
            'permission'=>'shipments.manage',
            'guide'=>'Staging cargo dispatch approval, arrival, buyer paperwork and official source validation boundaries.',
        ],
        'finance_controls'=>[
            'label'=>'Financial postings and reconciliation',
            'permission'=>'finance.view',
            'guide'=>'Qualified finance test: balances, FX, invoices, credits, returns, immutable journals and two-person reviews.',
        ],
        'staff_access_mfa'=>[
            'label'=>'Staff access and MFA',
            'permission'=>'staff.manage',
            'guide'=>'Staging role changes, suspension, MFA, sessions and attendance access restrictions.',
        ],
        'partner_notifications'=>[
            'label'=>'External integrations and print fallback',
            'permission'=>'integrations.view',
            'guide'=>'Verify the agreed feature scope, disabled or unavailable providers, signed callbacks and safe manual fallback.',
        ],
        'penetration_test'=>[
            'label'=>'External security assessment evidence',
            'permission'=>null,
            'guide'=>'Independent security assessor report reference, remediation acceptance and explicit residual risks.',
        ],
        'accountant_signoff'=>[
            'label'=>'Independent accounting sign-off evidence',
            'permission'=>null,
            'guide'=>'Qualified external accountant review of Uganda/destination obligations, reporting and manual settlement boundaries.',
        ],
        'backup_restore'=>[
            'label'=>'Encrypted backup and independent restore drill',
            'permission'=>null,
            'guide'=>'Evidence of recent offsite encrypted archive restoration, RPO/RTO, key escrow and independent recovery check.',
        ],
        'cutover_rehearsal'=>[
            'label'=>'SQLite/PostgreSQL data cutover rehearsal',
            'permission'=>null,
            'guide'=>'Isolated PostgreSQL import, tenant row counts, finance hashes, foreign keys, sequences and final delta proof.',
        ],
        'operations_handover'=>[
            'label'=>'Hosting, incident response and release handover',
            'permission'=>null,
            'guide'=>'Actual HTTPS, SMTP, private R2, Redis, queues, monitoring, contacts, rollback and provider/hardware limits.',
        ],
    ];

    private function member(int $businessId,int $actor):BusinessMembership
    {
        return BusinessMembership::query()
            ->where('business_id',$businessId)->where('user_id',$actor)
            ->where('active',true)->whereHas('business',fn($q)=>$q->where('is_active',true))
            ->with('business')->firstOrFail();
    }

    private function owner(BusinessMembership $member):void
    {
        abort_unless($member->role==='owner',403,
            'Only a company owner can open or seal a release acceptance campaign.');
    }

    private function unlocked(int $businessId,int $runId):ReleaseAcceptanceRun
    {
        $run=ReleaseAcceptanceRun::query()->where('business_id',$businessId)
            ->lockForUpdate()->findOrFail($runId);
        if($run->status!=='open'){
            throw ValidationException::withMessages(['release'=>
                'This acceptance campaign is sealed and cannot be modified.']);
        }
        return $run;
    }

    private function event(ReleaseAcceptanceRun $run,?ReleaseAcceptanceCheck $check,
        string $action,int $actor,string $reason,?string $hash=null):void
    {
        ReleaseAcceptanceEvent::create([
            'business_id'=>$run->business_id,'run_id'=>$run->id,
            'check_id'=>$check?->id,'action'=>$action,'actor_id'=>$actor,
            'reason'=>$reason,'evidence_sha256'=>$hash,'created_at'=>now(),
        ]);
    }

    public function begin(Business $business,array $data,int $actor):ReleaseAcceptanceRun
    {
        $this->owner($this->member($business->id,$actor));
        return DB::transaction(function()use($business,$data,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $run=ReleaseAcceptanceRun::create([
                'business_id'=>$business->id,
                'artifact_sha256'=>strtolower($data['artifact_sha256']),
                'assets_sha256'=>strtolower($data['assets_sha256']),
                'release_label'=>$data['release_label'],
                'scope_note'=>$data['scope_note'],
                'status'=>'open','created_by'=>$actor,
            ]);
            foreach(array_keys(self::CHECKS) as $code){
                ReleaseAcceptanceCheck::create([
                    'business_id'=>$business->id,'run_id'=>$run->id,
                    'check_code'=>$code,'status'=>'pending',
                ]);
            }
            $this->event($run,null,'started',$actor,$data['scope_note']);
            return $run;
        },3);
    }

    public function submit(Business $business,int $runId,string $code,
        string $evidence,string $notes,int $actor):ReleaseAcceptanceCheck
    {
        abort_unless(isset(self::CHECKS[$code]),404);
        $member=$this->member($business->id,$actor);
        $permission=self::CHECKS[$code]['permission'];
        if($permission!==null){
            abort_unless(app(BusinessPermissions::class)->allows($member,$permission),403);
        }else{
            abort_unless(in_array($member->role,['owner','admin'],true),403);
        }
        return DB::transaction(function()use($business,$runId,$code,$evidence,$notes,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $run=$this->unlocked($business->id,$runId);
            $check=ReleaseAcceptanceCheck::query()
                ->where('business_id',$business->id)->where('run_id',$run->id)
                ->where('check_code',$code)->lockForUpdate()->firstOrFail();
            if(!in_array($check->status,['pending','rejected'],true)){
                throw ValidationException::withMessages(['check'=>
                    'An already submitted or accepted check cannot be overwritten.']);
            }
            $check->update([
                'status'=>'submitted','evidence_reference'=>$evidence,
                'test_notes'=>$notes,'tested_by'=>$actor,'tested_at'=>now(),
                'reviewed_by'=>null,'review_notes'=>null,'reviewed_at'=>null,
            ]);
            $this->event($run,$check,'submitted',$actor,$notes,
                hash('sha256',$evidence."\n".$notes));
            return $check;
        },3);
    }

    public function review(Business $business,int $runId,string $code,
        bool $approve,string $notes,int $actor):ReleaseAcceptanceCheck
    {
        abort_unless(isset(self::CHECKS[$code]),404);
        $member=$this->member($business->id,$actor);
        abort_unless(in_array($member->role,['owner','admin'],true),403);
        return DB::transaction(function()use($business,$runId,$code,$approve,$notes,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $run=$this->unlocked($business->id,$runId);
            $check=ReleaseAcceptanceCheck::query()
                ->where('business_id',$business->id)->where('run_id',$run->id)
                ->where('check_code',$code)->lockForUpdate()->firstOrFail();
            if($check->status!=='submitted' || $check->tested_by===$actor){
                throw ValidationException::withMessages(['review'=>
                    'An independently authorised reviewer must assess the pending evidence.']);
            }
            if(!$check->evidence_reference || !$check->test_notes){
                throw ValidationException::withMessages(['review'=>
                    'Missing test evidence cannot be signed off.']);
            }
            $check->update([
                'status'=>$approve?'approved':'rejected',
                'reviewed_by'=>$actor,'review_notes'=>$notes,
                'reviewed_at'=>now(),
            ]);
            $this->event($run,$check,$approve?'approved':'rejected',
                $actor,$notes,hash('sha256',$check->evidence_reference."\n".$check->test_notes));
            return $check;
        },3);
    }

    public function seal(Business $business,int $runId,string $notes,int $actor):ReleaseAcceptanceRun
    {
        $this->owner($this->member($business->id,$actor));
        return DB::transaction(function()use($business,$runId,$notes,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $run=$this->unlocked($business->id,$runId);
            $checks=ReleaseAcceptanceCheck::query()->where('business_id',$business->id)
                ->where('run_id',$runId)->lockForUpdate()->get()->keyBy('check_code');
            foreach(array_keys(self::CHECKS) as $code){
                $check=$checks->get($code);
                if(!$check || $check->status!=='approved'
                    || !$check->tested_by || !$check->reviewed_by
                    || $check->tested_by===$check->reviewed_by){
                    throw ValidationException::withMessages(['release'=>
                        'Every mandatory test and external-evidence category requires a separate accepted reviewer before sealing.']);
                }
            }
            $run->update(['status'=>'sealed','sealed_by'=>$actor,'sealed_at'=>now()]);
            $this->event($run,null,'sealed',$actor,$notes);
            return $run;
        },3);
    }
}
