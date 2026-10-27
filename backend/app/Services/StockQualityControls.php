<?php

namespace App\Services;

use App\Models\Business;
use App\Models\StockBatch;
use App\Models\StockExceptionCase;
use App\Models\StockQualityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockQualityControls
{
    public function change(Business $business, int $batchId, bool $hold, string $reason, int $actor): StockBatch
    {
        return DB::transaction(function () use ($business, $batchId, $hold, $reason, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $batch = StockBatch::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($batchId);
            if (! $hold) {
                abort_if(StockExceptionCase::query()->where('business_id', $business->id)->where('kind', 'recall')->whereNotIn('status', ['closed', 'rejected', 'withdrawn'])->whereIn('batch_id', app(StockExceptionCases::class)->ancestors($business->id, $batchId))->exists(), 422, 'Resolve the open recall case before releasing affected stock.');
            }
            if ($hold === (bool) $batch->is_quarantined) {
                throw ValidationException::withMessages(['quality' => $hold ? 'This stock batch is already on quality hold.' : 'This stock batch is not on hold.']);
            }
            $batch->update($hold ? [
                'is_quarantined' => true, 'quality_hold_reason' => $reason,
                'quality_hold_by' => $actor, 'quality_held_at' => now(),
            ] : [
                'is_quarantined' => false, 'quality_hold_reason' => null,
                'quality_hold_by' => null, 'quality_held_at' => null,
            ]);
            StockQualityEvent::create([
                'business_id' => $business->id, 'batch_id' => $batch->id,
                'actor_id' => $actor, 'action' => $hold ? 'held' : 'released',
                'reason' => $reason, 'created_at' => now(),
            ]);

            return $batch;
        }, 3);
    }
}
