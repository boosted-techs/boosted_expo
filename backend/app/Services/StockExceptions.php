<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Carton;
use App\Models\CartonRepack;
use App\Models\FinanceInventoryRelief;
use App\Models\QrIdentity;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockLocationEvent;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockExceptions
{
    public function defaultLocation(int $businessId): StockLocation
    {
        return StockLocation::query()->firstOrCreate(['business_id' => $businessId, 'name' => 'Main packhouse'], ['active' => true]);
    }

    public function fingerprint(StockBatch $batch): string
    {
        return hash('sha256', json_encode([
            'batch' => $batch->only(['id', 'business_id', 'stock_location_id', 'is_quarantined']),
            'movements' => StockMovement::query()->where('business_id', $batch->business_id)->where('batch_id', $batch->id)->orderBy('id')->get(['id', 'quantity', 'unit', 'movement_type'])->toArray(),
            'reservations' => StockReservation::query()->where('business_id', $batch->business_id)->where('batch_id', $batch->id)->orderBy('id')->get(['id', 'quantity', 'consumed_quantity', 'lost_quantity', 'released_at'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function count(Business $business, array $data, int $actor): StockAdjustment
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = StockAdjustment::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless($prior->payload_hash === $hash, 422, 'This count request already has different details.');

                return $prior;
            }
            $batch = StockBatch::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['batch_id']);
            abort_if(StockAdjustment::query()->where('business_id', $business->id)->where('batch_id', $batch->id)->whereIn('status', ['pending', 'approved'])->where('posting_status', 'not_posted')->exists(), 422, 'Finish or reject the existing count for this batch first.');
            $expected = app(StockAvailability::class)->available($business->id, $batch->id);
            $counted = BigDecimal::of((string) $data['counted_quantity']);
            abort_unless(! $counted->isLessThan(0), 422, 'Counted quantity cannot be negative.');
            abort_unless($counted->isGreaterThanOrEqualTo(app(OrderSourcing::class)->reserved($business->id, $batch->id)), 422, 'Resolve affected order reservations before a count below reserved stock.');
            $snapshot = ['fingerprint' => $this->fingerprint($batch), 'expected' => (string) $expected, 'counted' => (string) $counted, 'delta' => (string) $counted->minus($expected), 'batch_id' => $batch->id, 'unit' => $batch->product->base_unit, 'reason' => $data['reason'], 'created_by' => $actor, 'event_on' => now($business->timezone)->toDateString()];

            return StockAdjustment::create(['business_id' => $business->id, 'batch_id' => $batch->id, 'reference' => 'COUNT-'.Str::upper((string) Str::ulid()), 'event_on' => now($business->timezone)->toDateString(), 'expected_quantity' => (string) $expected, 'counted_quantity' => (string) $counted, 'quantity_delta' => $snapshot['delta'], 'unit' => $snapshot['unit'], 'currency' => $business->base_currency, 'reason' => $data['reason'], 'status' => 'pending', 'posting_status' => 'not_posted', 'data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }

    private function assertNoPendingRelief(int $businessId, int $batchId): void
    {
        foreach (FinanceInventoryRelief::query()->where('business_id', $businessId)->where('posting_status', 'not_posted')->cursor() as $relief) {
            abort_if(collect($relief->data_snapshot['lines'] ?? [])->contains(fn ($line) => (int) $line['batch_id'] === $batchId), 422, 'Finish the pending COGS or waste valuation for this batch before approving a count variance.');
        }
    }

    public function review(StockAdjustment $count, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($count, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($count->business_id, 'procurement');
            $count = StockAdjustment::query()->where('business_id', $count->business_id)->lockForUpdate()->findOrFail($count->id);
            abort_unless($count->status === 'pending' && $count->created_by !== $actor, 422, 'A different authorised person must review the pending count.');
            if (! $approve) {
                $count->update(['status' => 'rejected', 'posting_status' => 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);

                return;
            }
            $batch = StockBatch::query()->where('business_id', $count->business_id)->lockForUpdate()->findOrFail($count->batch_id);
            $snapshot = $count->data_snapshot;
            abort_unless($snapshot['fingerprint'] === $this->fingerprint($batch), 422, 'Stock or reservations changed after counting. Reject and recount the current stock.');
            $delta = BigDecimal::of((string) $count->quantity_delta);
            $amount = '0.00';
            if (! $delta->isZero()) {
                $this->assertNoPendingRelief($count->business_id, $count->batch_id);
                $basis = app(FinanceCostBasis::class)->batchBasis($batch);
                $amount = (string) app(FinanceMath::class)->money($delta->abs()->multipliedBy($basis['unit']));
                abort_unless(BigDecimal::of($amount)->isGreaterThan(0), 422, 'Count variance is below ledger precision.');
                $snapshot['basis'] = ['quantity' => (string) $basis['quantity'], 'cost' => (string) $basis['cost'], 'unit' => (string) $basis['unit']];
            }
            $count->update(['status' => 'approved', 'posting_status' => $delta->isZero() ? 'not_required' : 'not_posted', 'amount' => $amount, 'data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
        }, 3);
    }

    public function lines(StockAdjustment $count): array
    {
        $business = app(OrderOperations::class)->lockForWork($count->business_id, 'procurement');
        $snapshot = $count->data_snapshot;
        abort_unless($count->status === 'approved' && hash_equals($count->snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))), 422, 'The count must be independently approved and unchanged.');
        $batch = StockBatch::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($count->batch_id);
        abort_unless($snapshot['fingerprint'] === $this->fingerprint($batch) && $snapshot['batch_id'] === $count->batch_id && BigDecimal::of($snapshot['delta'])->isEqualTo((string) $count->quantity_delta) && $count->currency === $business->base_currency && $snapshot['reason'] === $count->reason && $snapshot['created_by'] === $count->created_by && $snapshot['event_on'] === $count->event_on->toDateString() && BigDecimal::of($snapshot['expected'])->isEqualTo((string) $count->expected_quantity) && BigDecimal::of($snapshot['counted'])->isEqualTo((string) $count->counted_quantity), 422, 'Stock, reservations or the approved count changed before posting. Recount before applying a journal.');
        $this->assertNoPendingRelief($count->business_id, $count->batch_id);
        $basis = app(FinanceCostBasis::class)->batchBasis($batch);
        $delta = BigDecimal::of((string) $count->quantity_delta);
        $amount = app(FinanceMath::class)->money($delta->abs()->multipliedBy($basis['unit']));
        abort_unless(! $delta->isZero() && $amount->isEqualTo((string) $count->amount) && (string) $basis['cost'] === $snapshot['basis']['cost'] && (string) $basis['quantity'] === $snapshot['basis']['quantity'], 422, 'The stock cost basis changed. Prepare a new count review.');
        $ledger = app(FinanceLedger::class);

        return $delta->isLessThan(0) ? [$ledger->line('stock_count_loss', (string) $amount, '0', (string) $amount, '0', 'Approved loose stock shortage'), $ledger->line('inventory', '0', (string) $amount, '0', (string) $amount, 'Stock count adjustment')] : [$ledger->line('inventory', (string) $amount, '0', (string) $amount, '0', 'Approved surplus in the identified source batch'), $ledger->line('stock_count_gain', '0', (string) $amount, '0', (string) $amount, 'Approved stock count surplus')];
    }

    public function finalize(StockAdjustment $count, int $actor): void
    {
        StockMovement::create(['business_id' => $count->business_id, 'batch_id' => $count->batch_id, 'movement_type' => 'count_adjustment', 'quantity' => (string) $count->quantity_delta, 'unit' => $count->unit, 'source_type' => 'stock_adjustment', 'source_id' => $count->id, 'created_by' => $actor]);
        $count->update(['posting_status' => 'posted', 'posted_at' => now()]);
    }

    public function move(Business $business, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = StockLocationEvent::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless($prior->payload_hash === $hash, 422, 'This move request already has different details.');

                return;
            }
            $location = StockLocation::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['to_location_id']);
            $carton = ! empty($data['carton_id']) ? Carton::query()->where('business_id', $business->id)->with('packingJob.product')->lockForUpdate()->findOrFail($data['carton_id']) : null;
            $batch = ! empty($data['batch_id']) ? StockBatch::query()->where('business_id', $business->id)->with('product')->lockForUpdate()->findOrFail($data['batch_id']) : null;
            abort_unless(($carton !== null) !== ($batch !== null), 422, 'Choose one loose batch or packed carton to move.');
            $record = $carton ?? $batch;
            if ($carton) {
                abort_unless($carton->status === 'packed' && ! $carton->shipmentAllocation()->exists(), 422, 'Only unallocated, unshipped cartons can change internal location.');
            }
            $from = $record->stock_location_id ?? $this->defaultLocation($business->id)->id;
            abort_unless($from !== $location->id, 422, 'The stock is already at this location.');
            $quantity = $carton ? BigDecimal::of((string) $carton->net_weight_kg) : app(StockAvailability::class)->available($business->id, $batch->id);
            abort_unless($quantity->isGreaterThan(0), 422, 'This batch has no loose stock to move.');
            $unit = $carton ? 'kg' : $batch->product->base_unit;
            StockLocationEvent::create(['business_id' => $business->id, 'batch_id' => $batch?->id, 'carton_id' => $carton?->id, 'from_location_id' => $from, 'to_location_id' => $location->id, 'quantity' => (string) $quantity, 'unit' => $unit, 'reason' => $data['reason'], 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
            $record->update(['stock_location_id' => $location->id]);
        }, 3);
    }

    private function cartonSnapshot(Carton $carton): array
    {
        abort_unless($carton->status === 'packed' && ! $carton->shipmentAllocation()->exists(), 422, 'Only a packed carton that is not allocated or shipped can be unpacked.');

        return ['packing_job' => $carton->packingJob->only(['id', 'status', 'completed_by', 'completed_at', 'sales_order_line_id']), 'carton' => $carton->only(['id', 'business_id', 'packing_job_id', 'net_weight_kg', 'gross_weight_kg', 'status', 'stock_location_id']), 'contents' => $carton->contents()->orderBy('batch_id')->get(['batch_id', 'quantity'])->toArray()];
    }

    public function requestRepack(Business $business, array $data, int $actor): CartonRepack
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'packing');
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = CartonRepack::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless($prior->payload_hash === $hash, 422, 'This repack request has other details.');

                return $prior;
            }
            $carton = Carton::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['carton_id']);
            $snapshot = $this->cartonSnapshot($carton);
            abort_if(CartonRepack::query()->where('business_id', $business->id)->where('carton_id', $carton->id)->where('status', 'pending')->exists(), 422, 'This carton already has a repack review.');

            return CartonRepack::create(['business_id' => $business->id, 'carton_id' => $carton->id, 'reason' => $data['reason'], 'status' => 'pending', 'data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }

    public function reviewRepack(CartonRepack $request, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($request, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($request->business_id, 'packing');
            $request = CartonRepack::query()->where('business_id', $request->business_id)->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'pending' && $request->created_by !== $actor, 422, 'A different authorised person must review a pending repack.');
            if ($approve) {
                $carton = Carton::query()->where('business_id', $request->business_id)->with('packingJob')->lockForUpdate()->findOrFail($request->carton_id);
                $snapshot = $this->cartonSnapshot($carton);
                abort_unless(hash_equals($request->snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))) && hash_equals($request->snapshot_hash, hash('sha256', json_encode($request->data_snapshot, JSON_THROW_ON_ERROR))), 422, 'The carton contents or state changed. Review a new unpack request.');
                foreach ($snapshot['contents'] as $row) {
                    $batch = StockBatch::query()->where('business_id', $request->business_id)->lockForUpdate()->findOrFail($row['batch_id']);
                    $qty = BigDecimal::of((string) $row['quantity']);
                    if ($carton->packingJob->sales_order_line_id) {
                        $remaining = $qty;
                        foreach (StockReservation::query()->where('business_id', $request->business_id)->where('sales_order_line_id', $carton->packingJob->sales_order_line_id)->where('batch_id', $batch->id)->where('consumed_quantity', '>', 0)->orderByDesc('id')->lockForUpdate()->get() as $reservation) {
                            $used = BigDecimal::min($remaining, (string) $reservation->consumed_quantity);
                            $reservation->update(['consumed_quantity' => (string) BigDecimal::of((string) $reservation->consumed_quantity)->minus($used)]);
                            $remaining = $remaining->minus($used);
                            if ($remaining->isZero()) {
                                break;
                            }
                        }
                        abort_unless($remaining->isZero(), 422, 'The original order reservation consumption does not reconcile.');
                    }
                    StockMovement::create(['business_id' => $request->business_id, 'batch_id' => $batch->id, 'movement_type' => 'carton_unpacked', 'quantity' => (string) $qty, 'unit' => $batch->product->base_unit, 'source_type' => 'carton_repack', 'source_id' => $request->id, 'created_by' => $actor]);
                }
                $carton->update(['status' => 'repacked']);
                QrIdentity::query()->where('business_id', $request->business_id)->where('level', 'carton')->where('record_id', $carton->id)->update(['public_enabled' => false]);
                if ($carton->packingJob->status === 'completed') {
                    $carton->packingJob->update(['status' => 'in_progress', 'completed_by' => null, 'completed_at' => null]);
                }
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
        }, 3);
    }
}
