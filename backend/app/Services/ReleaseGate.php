<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ReleaseAcceptanceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseGate
{
    public function __construct(
        private readonly OperationsReadiness $operations,
        private readonly ReleaseIntegrityInspector $integrity,
        private readonly ReleaseArtifactVerifier $verifier,
        private readonly ReleaseBuildVerifier $buildVerifier,
    ) {}

    private function independentlyAccepted(?ReleaseAcceptanceRun $run,
        string $sourceSha,string $assetsSha):bool
    {
        return $run!==null
            && $run->artifact_sha256===$sourceSha
            && $run->assets_sha256===$assetsSha
            && $run->status==='sealed'
            && $run->sealed_at!==null && $run->sealed_by!==null
            && $run->checks->count()===count(ReleaseAcceptanceManager::CHECKS)
            && $run->checks->every(fn($c)=>$c->status==='approved'
                && $c->tested_by && $c->reviewed_by
                && $c->tested_by!==$c->reviewed_by);
    }

    public function inspect(Business $business,string $requestedSha,bool $live=false):array
    {
        $items=[];
        $item=function(string $code,bool $ok,string $detail,
            bool $required=true)use(&$items):void{
            $items[]=[
                'code'=>$code,'status'=>$ok?'ok':($required?'fail':'warning'),
                'detail'=>$detail,
            ];
        };
        $valid=(bool)preg_match('/^[a-f0-9]{64}$/D',$requestedSha);
        $item('release_hash_syntax',$valid,
            'Release acceptance requires a SHA-256 digest of the actual immutable release archive.');
        $configured=(string)config('expo_release.artifact_sha256');
        $item('artifact_hash_bound',$valid && strlen($configured)===64
            && hash_equals($configured,$requestedSha),
            'The runtime must explicitly identify the same approved deployment archive hash.');
        $path=(string)config('expo_release.artifact_path');
        $exists=$path!=='' && !is_link($path) && is_file($path) && is_readable($path);
        $item('release_artifact_present',$exists,
            'The immutable deployment archive must be accessible for independent SHA-256 checking.');
        $actualHash=$exists?hash_file('sha256',$path):null;
        $item('release_artifact_verified',$valid && is_string($actualHash)
            && hash_equals($requestedSha,$actualHash),
            'Archive content must match the release hash; a filename or configured value alone is not evidence.');
        $sourceProof=$exists && $valid && is_string($actualHash)
            && hash_equals($requestedSha,$actualHash)
            ?$this->verifier->compare($path)
            :['ok'=>false,'compared'=>0,'missing_or_changed'=>0,'unexpected_source'=>0];
        $item('deployed_source_matches_archive',$sourceProof['ok'],
            'Verify current source files against the same approved archive (files compared: '.
            $sourceProof['compared'].', missing/changed: '.$sourceProof['missing_or_changed'].
            ', unexpected sources: '.$sourceProof['unexpected_source'].').');

        $built=$this->buildVerifier->inspect();
        $configBuild=strtolower((string)config('expo_release.assets_sha256'));
        $assetValid=(bool)preg_match('/^[a-f0-9]{64}$/D',$configBuild);
        $item('compiled_frontend_build_valid',$built['ok'],
            'Compiled Vite assets and referenced manifest entrypoints must be present, with no unexpected scripts or symlinks. '.
            ($built['issue']??($built['files'].' hashed build files.')));
        $item('compiled_build_hash_bound',$built['ok'] && $assetValid
            && hash_equals($configBuild,(string)$built['sha256']),
            'The exact compiled frontend build SHA-256 must match the separately approved deployment configuration.');

        $item('production_company_active',(bool)$business->is_active,
            'Only an active business can have a production release accepted.');

        $run=$valid?ReleaseAcceptanceRun::query()
            ->where('business_id',$business->id)
            ->where('artifact_sha256',$requestedSha)
            ->orderByDesc('id')->with('checks')->first():null;
        $item('independent_business_acceptance',$valid && $assetValid
            && $this->independentlyAccepted($run,$requestedSha,$configBuild),
            'The latest campaign must seal the same source archive and compiled frontend fingerprint with independent reviews. External report references are human assertions, not third-party certification.');

        // A shared multi-tenant production release changes code for EVERY
        // active company. The caller's single company ID is not authority
        // to bypass missing acceptance or integrity defects at other tenants.
        $activeBusinesses=0;
        $missingApprovals=0;
        $tenantIntegrityFailures=0;
        try {
            Business::query()->where('is_active',true)->orderBy('id')
                ->chunkById(100,function($companies)use($business,$requestedSha,
                    $configBuild,&$activeBusinesses,&$missingApprovals,
                    &$tenantIntegrityFailures){
                    foreach($companies as $company){
                        $activeBusinesses++;
                        $latest=ReleaseAcceptanceRun::query()
                            ->where('business_id',$company->id)
                            ->where('artifact_sha256',$requestedSha)
                            ->with('checks')->orderByDesc('id')->first();
                        if(!$this->independentlyAccepted($latest,
                            $requestedSha,$configBuild)){
                            $missingApprovals++;
                        }
                        if($company->id!==$business->id){
                            try{
                                $result=$this->integrity->inspect($company);
                                if($result['errors']!==0 || $result['warnings']!==0){
                                    $tenantIntegrityFailures++;
                                }
                            }catch(\Throwable){
                                $tenantIntegrityFailures++;
                            }
                        }
                    }
                });
            $item('all_active_tenants_accepted',
                $valid && $assetValid && $activeBusinesses>0
                && $missingApprovals===0,
                'Every active company on the shared instance must accept both exact release fingerprints (active: '.
                $activeBusinesses.', missing/invalid: '.$missingApprovals.').');
            $item('all_active_tenants_integrity',
                $activeBusinesses>0 && $tenantIntegrityFailures===0,
                'Financial, stock and audit anomalies in another active company block the shared rollout (affected companies: '.
                $tenantIntegrityFailures.').');
        }catch(\Throwable){
            $item('all_active_tenants_accepted',false,
                'The full active-tenant acceptance inventory could not be verified.');
            $item('all_active_tenants_integrity',false,
                'All active tenant data integrity checks could not finish.');
        }

        $pending=[];
        try{
            $migrations=glob(database_path('migrations/*.php'))?:[];
            $executed=Schema::hasTable('migrations')
                ?DB::table('migrations')->pluck('migration')->all():[];
            foreach($migrations as $file){
                if(!in_array(pathinfo($file,PATHINFO_FILENAME),$executed,true)){
                    $pending[]=basename($file);
                }
            }
            $item('all_migrations_recorded',$pending===[],
                count($pending).' application migrations remain pending; do not auto-migrate during gate inspection.');
        }catch(\Throwable){
            $item('all_migrations_recorded',false,
                'Database migration metadata could not be inspected.');
        }

        try{
            $audit=$this->integrity->inspect($business);
            foreach($audit['checks'] as $check){
                $item('audit_'.$check['code'],
                    $check['status']==='ok',
                    $check['detail'].' (failures: '.$check['failures'].')');
            }
        }catch(\Throwable){
            $item('audit_available',false,
                'Read-only business data integrity inspection could not complete.');
        }
        // Non-live diagnostic never grants production authorisation.
        $item('real_environment_probes_executed',$live,
            'Run the final gate with --live on the approved host, never from a developer machine.');
        try{
            $preflight=$this->operations->inspect($live);
            foreach($preflight['checks'] as $check){
                $item('preflight_'.$check['code'],$check['status']==='ok',
                    $check['detail']);
            }
        }catch(\Throwable){
            $item('preflight_available',false,
                'Production readiness checks failed unexpectedly.');
        }

        $fail=count(array_filter($items,fn($i)=>$i['status']==='fail'));
        $warning=count(array_filter($items,fn($i)=>$i['status']==='warning'));
        return [
            'ready'=>$fail===0,'errors'=>$fail,'warnings'=>$warning,
            'business_id'=>$business->id,'artifact_sha256'=>$valid?$requestedSha:null,
            'checked_at'=>now()->toIso8601String(),
            'checks'=>$items,
            'note'=>'Read-only diagnostics only. A passing gate does not deploy code, certify a third-party report, validate every legal obligation or authorise a financial transfer.',
        ];
    }
}
