<?php

namespace App\Services;

use App\Models\Business;
use App\Models\LabelGeneration;
use App\Models\LabelPrintAgent;
use App\Models\LabelPrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LabelPrintQueue
{
    public function register(Business $business,string $name,int $actor):array
    {
        $secret=Str::random(64);
        $agent=DB::transaction(function()use($business,$name,$actor,$secret){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            return LabelPrintAgent::create([
                'business_id'=>$business->id,'name'=>$name,
                'credential_hash'=>hash('sha256',$secret),
                'active'=>true,'created_by'=>$actor,
            ]);
        },3);
        return [$agent,$secret];
    }

    public function revoke(Business $business,int $agentId,int $actor):void
    {
        DB::transaction(function()use($business,$agentId,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $agent=LabelPrintAgent::query()->where('business_id',$business->id)
                ->lockForUpdate()->findOrFail($agentId);
            $agent->update(['active'=>false,'revoked_by'=>$actor]);
            LabelPrintJob::query()->where('business_id',$business->id)
                ->where('agent_id',$agent->id)->whereIn('status',['queued','leased'])
                ->update(['status'=>'cancelled','lease_hash'=>null,'lease_until'=>null]);
        },3);
    }

    public function enqueue(Business $business,int $agentId,int $generationId,
        string $requestKey,int $actor):LabelPrintJob
    {
        return DB::transaction(function()use($business,$agentId,$generationId,$requestKey,$actor){
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing=LabelPrintJob::query()->where('business_id',$business->id)
                ->where('request_key',$requestKey)->first();
            if($existing){
                if($existing->agent_id!==$agentId || $existing->generation_id!==$generationId){
                    throw ValidationException::withMessages(['request_key'=>
                        'This request key belongs to a different print job.']);
                }
                return $existing;
            }
            $agent=LabelPrintAgent::query()->where('business_id',$business->id)
                ->where('active',true)->findOrFail($agentId);
            $generation=LabelGeneration::query()->where('business_id',$business->id)
                ->findOrFail($generationId);
            $zpl=(string)$generation->zpl_snapshot;
            if(!$zpl || strlen($zpl)>65536 || !str_starts_with($zpl,"^XA\n")
                || !str_ends_with(trim($zpl),'^XZ')
                || !hash_equals($generation->zpl_sha256,hash('sha256',$zpl))){
                throw ValidationException::withMessages(['generation_id'=>
                    'Label file is incomplete or its immutable fingerprint no longer matches.']);
            }
            return LabelPrintJob::create([
                'business_id'=>$business->id,'agent_id'=>$agent->id,
                'generation_id'=>$generation->id,'request_key'=>$requestKey,
                'status'=>'queued','requested_by'=>$actor,
            ]);
        },3);
    }

    public function authenticate(int $agentId,string $secret):LabelPrintAgent
    {
        if(!preg_match('/^[A-Za-z0-9]{64}$/D',$secret))abort(401,'Print agent authentication failed.');
        $agent=LabelPrintAgent::query()->whereKey($agentId)->where('active',true)
            ->first();
        if(!$agent||!hash_equals($agent->credential_hash,hash('sha256',$secret))
            ||!Business::query()->whereKey($agent->business_id)
                ->where('is_active',true)->exists()){
            abort(401,'Print agent authentication failed.');
        }
        return $agent;
    }

    public function claim(LabelPrintAgent $agent):?array
    {
        return DB::transaction(function()use($agent){
            Business::query()->whereKey($agent->business_id)->lockForUpdate()->firstOrFail();
            $agent=LabelPrintAgent::query()->where('business_id',$agent->business_id)
                ->where('active',true)->lockForUpdate()->findOrFail($agent->id);
            $agent->update(['last_seen_at'=>now()]);
            $job=LabelPrintJob::query()->where('business_id',$agent->business_id)
                ->where('agent_id',$agent->id)
                ->where(function($q){
                    $q->where('status','queued')->orWhere(function($expired){
                        $expired->where('status','leased')->where('lease_until','<',now());
                    });
                })->orderBy('id')->lockForUpdate()->first();
            if(!$job)return null;
            if($job->attempts>=3){
                $job->update(['status'=>'failed','lease_hash'=>null,'lease_until'=>null,
                    'failure_note'=>'Repeated delivery attempt expired. Manually requeue after checking the printer.']);
                return null;
            }
            $generation=LabelGeneration::query()->where('business_id',$agent->business_id)
                ->findOrFail($job->generation_id);
            $zpl=(string)$generation->zpl_snapshot;
            if(!str_starts_with($zpl,"^XA\n")||!str_ends_with(trim($zpl),'^XZ')
                || strlen($zpl)>65536
                || !hash_equals($generation->zpl_sha256,hash('sha256',$zpl))){
                $job->update(['status'=>'failed','failure_note'=>'Label snapshot integrity validation failed.']);
                return null;
            }
            $lease=Str::random(64);
            $job->update([
                'status'=>'leased','attempts'=>$job->attempts+1,
                'lease_hash'=>hash('sha256',$lease),'lease_until'=>now()->addSeconds(90),
                'failure_note'=>null,
            ]);
            return [
                'job_id'=>$job->id,'lease'=>$lease,'sha256'=>$generation->zpl_sha256,
                'zpl'=>$zpl,'label_generation_id'=>$generation->id,
                'expires_at'=>$job->lease_until->toIso8601String(),
            ];
        },3);
    }

    public function acknowledge(LabelPrintAgent $agent,int $jobId,string $lease,
        bool $success,?string $note):LabelPrintJob
    {
        return DB::transaction(function()use($agent,$jobId,$lease,$success,$note){
            Business::query()->whereKey($agent->business_id)->lockForUpdate()->firstOrFail();
            LabelPrintAgent::query()->where('business_id',$agent->business_id)
                ->where('active',true)->lockForUpdate()->findOrFail($agent->id);
            $job=LabelPrintJob::query()->where('business_id',$agent->business_id)
                ->where('agent_id',$agent->id)->lockForUpdate()->findOrFail($jobId);
            if(!preg_match('/^[A-Za-z0-9]{64}$/D',$lease)
                || !$job->lease_hash
                || !hash_equals($job->lease_hash,hash('sha256',$lease))){
                abort(403,'This job lease is not valid for the authenticated print agent.');
            }
            if($job->status==='transferred' && $success)return $job;
            if($job->status!=='leased' || !$job->lease_until
                || $job->lease_until->lessThan(now())){
                throw ValidationException::withMessages(['job'=>
                    'Print job claim expired or was already finalised. Do not silently report delivery.']);
            }
            $job->update([
                'status'=>$success?'transferred':'failed',
                'transferred_at'=>$success?now():null,
                'failure_note'=>$success?null:mb_substr((string)$note,0,250),
                'lease_until'=>null,
            ]);
            return $job;
        },3);
    }
}
