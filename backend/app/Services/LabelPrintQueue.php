<?php

namespace App\Services;

use App\Models\Business;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelPrintAgent;
use App\Models\LabelPrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LabelPrintQueue
{
    public function register(Business $business, string $name, int $actor, ?int $profileId = null): array
    {
        $secret = Str::random(64);
        $agent = DB::transaction(function () use ($business, $name, $actor, $secret, $profileId) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($profileId) {
                LabelMediaProfile::query()->where('business_id', $business->id)->findOrFail($profileId);
            }

            return LabelPrintAgent::create([
                'business_id' => $business->id, 'name' => $name,
                'credential_hash' => hash('sha256', $secret),
                'active' => true, 'created_by' => $actor, 'media_profile_id' => $profileId,
            ]);
        }, 3);

        return [$agent, $secret];
    }

    public function revoke(Business $business, int $agentId, int $actor): void
    {
        DB::transaction(function () use ($business, $agentId, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $agent = LabelPrintAgent::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($agentId);
            $agent->update(['active' => false, 'revoked_by' => $actor]);
            LabelPrintJob::query()->where('business_id', $business->id)->where('agent_id', $agent->id)->where('status', 'leased')
                ->update(['status' => 'uncertain', 'lease_hash' => null, 'lease_until' => null, 'failure_note' => 'Workstation revoked while delivery was in progress. Inspect physical output.']);
            LabelPrintJob::query()->where('business_id', $business->id)->where('agent_id', $agent->id)->where('status', 'queued')
                ->update(['status' => 'cancelled', 'lease_hash' => null, 'lease_until' => null]);
        }, 3);
    }

    public function enqueue(Business $business, int $agentId, int $generationId,
        string $requestKey, int $actor, int $copies = 1, ?string $reason = null, ?int $parentId = null): LabelPrintJob
    {
        return DB::transaction(function () use ($business, $agentId, $generationId, $requestKey, $actor, $copies, $reason, $parentId) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing = LabelPrintJob::query()->where('business_id', $business->id)
                ->where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->agent_id !== $agentId || $existing->generation_id !== $generationId || $existing->copies !== $copies) {
                    throw ValidationException::withMessages(['request_key' => 'This request key belongs to a different print job.']);
                }

                return $existing;
            }
            $agent = LabelPrintAgent::query()->where('business_id', $business->id)
                ->where('active', true)->findOrFail($agentId);
            $generation = LabelGeneration::query()->where('business_id', $business->id)
                ->findOrFail($generationId);
            if ($copies < 1 || $copies > 10) {
                throw ValidationException::withMessages(['copies' => 'Choose between one and ten copies.']);
            }
            if ($generation->source_sha256 && app(LabelWorkflow::class)->stale($generation)) {
                throw ValidationException::withMessages(['generation_id' => 'This sticker is stale. Generate a replacement before queueing.']);
            }
            $profile = $generation->template_snapshot['media_profile_id'] ?? null;
            if (($generation->template_snapshot['profile']['protocol'] ?? 'zpl') !== 'zpl' || ($profile && $agent->media_profile_id !== $profile)) {
                throw ValidationException::withMessages(['agent_id' => 'Choose a ZPL workstation configured for this sticker media profile. PDF-only printers use the PDF download.']);
            }
            if (LabelPrintJob::query()->where('business_id', $business->id)->where('generation_id', $generationId)->exists() && mb_strlen(trim((string) $reason)) < 10) {
                throw ValidationException::withMessages(['reason' => 'Explain why another print is needed and check existing physical stickers first.']);
            }
            $zpl = (string) $generation->zpl_snapshot;
            if (! $zpl || strlen($zpl) > 1048576 || ! str_starts_with($zpl, "^XA\n")
                || ! str_ends_with(trim($zpl), '^XZ')
                || ! hash_equals($generation->zpl_sha256, hash('sha256', $zpl))) {
                throw ValidationException::withMessages(['generation_id' => 'Label file is incomplete or its immutable fingerprint no longer matches.']);
            }
            $job = LabelPrintJob::create([
                'business_id' => $business->id, 'agent_id' => $agent->id,
                'generation_id' => $generation->id, 'request_key' => $requestKey,
                'status' => 'queued', 'requested_by' => $actor, 'copies' => $copies, 'parent_job_id' => $parentId, 'operator_reason' => $reason,
            ]);
            app(LabelWorkflow::class)->event($business->id, $generation->id, $job->id, $parentId ? 'reprint_queued' : 'queued', $reason ?: 'Initial print request; '.$copies.' copies.', $actor);

            return $job;
        }, 3);
    }

    public function authenticate(int $agentId, string $secret): LabelPrintAgent
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/D', $secret)) {
            abort(401, 'Print agent authentication failed.');
        }
        $agent = LabelPrintAgent::query()->whereKey($agentId)->where('active', true)
            ->first();
        if (! $agent || ! hash_equals($agent->credential_hash, hash('sha256', $secret))
            || ! Business::query()->whereKey($agent->business_id)
                ->where('is_active', true)->exists()) {
            abort(401, 'Print agent authentication failed.');
        }

        return $agent;
    }

    public function claim(LabelPrintAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent) {
            $business = Business::query()->whereKey($agent->business_id)->lockForUpdate()->firstOrFail();
            abort_unless($business->is_active, 401);
            $agent = LabelPrintAgent::query()->where('business_id', $agent->business_id)
                ->where('active', true)->lockForUpdate()->findOrFail($agent->id);
            $agent->update(['last_seen_at' => now()]);
            LabelPrintJob::query()->where('business_id', $agent->business_id)->where('agent_id', $agent->id)
                ->where('status', 'leased')->where('lease_until', '<', now())->update(['status' => 'uncertain', 'failure_note' => 'Delivery acknowledgement expired. Inspect the printer before retrying.', 'lease_until' => null]);
            $job = LabelPrintJob::query()->where('business_id', $agent->business_id)
                ->where('agent_id', $agent->id)->where('status', 'queued')->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            if ($job->attempts >= 3) {
                $job->update(['status' => 'failed', 'lease_hash' => null, 'lease_until' => null,
                    'failure_note' => 'Repeated delivery attempt expired. Manually requeue after checking the printer.']);

                return null;
            }
            $generation = LabelGeneration::query()->where('business_id', $agent->business_id)
                ->findOrFail($job->generation_id);
            if ($generation->source_sha256 && app(LabelWorkflow::class)->stale($generation)) {
                $job->update(['status' => 'failed', 'failure_note' => 'Source data changed before transfer. Generate replacement stickers.']);

                return null;
            }
            $zpl = (string) $generation->zpl_snapshot;
            if (! str_starts_with($zpl, "^XA\n") || ! str_ends_with(trim($zpl), '^XZ')
                || strlen($zpl) > 1048576
                || ! hash_equals($generation->zpl_sha256, hash('sha256', $zpl))) {
                $job->update(['status' => 'failed', 'failure_note' => 'Label snapshot integrity validation failed.']);

                return null;
            }
            $lease = Str::random(64);
            $job->update([
                'status' => 'leased', 'attempts' => $job->attempts + 1,
                'lease_hash' => hash('sha256', $lease), 'lease_until' => now()->addSeconds(90),
                'failure_note' => null,
            ]);

            return [
                'job_id' => $job->id, 'lease' => $lease, 'sha256' => $generation->zpl_sha256,
                'zpl' => $zpl, 'label_generation_id' => $generation->id, 'copies' => $job->copies,
                'expires_at' => $job->lease_until->toIso8601String(),
            ];
        }, 3);
    }

    public function acknowledge(LabelPrintAgent $agent, int $jobId, string $lease,
        bool $success, ?string $note): LabelPrintJob
    {
        return DB::transaction(function () use ($agent, $jobId, $lease, $success, $note) {
            $business = Business::query()->whereKey($agent->business_id)->lockForUpdate()->firstOrFail();
            abort_unless($business->is_active, 401);
            LabelPrintAgent::query()->where('business_id', $agent->business_id)
                ->where('active', true)->lockForUpdate()->findOrFail($agent->id);
            $job = LabelPrintJob::query()->where('business_id', $agent->business_id)
                ->where('agent_id', $agent->id)->lockForUpdate()->findOrFail($jobId);
            if (! preg_match('/^[A-Za-z0-9]{64}$/D', $lease)
                || ! $job->lease_hash
                || ! hash_equals($job->lease_hash, hash('sha256', $lease))) {
                abort(403, 'This job lease is not valid for the authenticated print agent.');
            }
            if ($job->status === 'transferred' && $success) {
                return $job;
            }
            if ($job->status !== 'leased' || ! $job->lease_until
                || $job->lease_until->lessThan(now())) {
                throw ValidationException::withMessages(['job' => 'Print job claim expired or was already finalised. Do not silently report delivery.']);
            }
            $job->update([
                'status' => $success ? 'transferred' : 'uncertain',
                'transferred_at' => $success ? now() : null,
                'failure_note' => $success ? null : mb_substr($note ?: 'Workstation could not confirm transfer. Inspect the printer before retrying.', 0, 250),
                'lease_until' => null,
            ]);

            return $job;
        }, 3);
    }

    public function recover(Business $business, int $jobId, string $action, string $reason, string $key, int $actor): void
    {
        DB::transaction(function () use ($business, $jobId, $action, $reason, $key, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $job = LabelPrintJob::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($jobId);
            $child = LabelPrintJob::query()->where('business_id', $business->id)->where('request_key', $key)->first();
            if ($child) {
                if ($action !== 'retry' || $child->parent_job_id !== $job->id || $child->operator_reason !== $reason) {
                    throw ValidationException::withMessages(['request_key' => 'This request key belongs to another recovery action.']);
                }

                return;
            }
            if ($job->status === 'leased' && $job->lease_until?->isPast()) {
                $job->update(['status' => 'uncertain']);
            }
            if ($job->status === 'leased') {
                throw ValidationException::withMessages(['job' => 'This workstation is still delivering the job. Wait for its acknowledgement or lease expiry.']);
            }
            if ($action === 'cancel') {
                abort_unless(in_array($job->status, ['queued', 'failed', 'uncertain'], true), 422, 'This job cannot be cancelled. Record physical verification or a controlled reprint.');
                $job->update(['status' => 'cancelled', 'lease_hash' => null, 'lease_until' => null]);
            } elseif ($action === 'resolve_transferred') {
                abort_unless($job->status === 'uncertain', 422, 'Only an uncertain delivery can be resolved.');
                $job->update(['status' => 'transferred', 'transferred_at' => now(), 'lease_hash' => null, 'lease_until' => null]);
            } else {
                abort_unless(in_array($job->status, ['failed', 'uncertain', 'transferred'], true), 422, 'Only failed, uncertain or transferred jobs can be reprinted.');
                $this->enqueue($business,$job->agent_id,$job->generation_id,$key,$actor,$job->copies,$reason,$job->id);
                if ($job->status !== 'transferred') {
                    $job->update(['status' => 'resolved', 'lease_hash' => null, 'lease_until' => null]);
                }
            }
            app(LabelWorkflow::class)->event($business->id,$job->generation_id,$job->id,$action,$reason,$actor);
        }, 3);
    }
}
