<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Carton;
use App\Models\CartonContent;
use App\Models\PackingJob;
use App\Models\PackingWasteEntry;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackingOperations
{
    private function lockJob(PackingJob $job): PackingJob
    {
        Business::query()->whereKey($job->business_id)->lockForUpdate()->firstOrFail();
        if ($job->sales_order_line_id) {
            app(OrderSourcing::class)->line($job->business_id, $job->sales_order_line_id);
        }

        return PackingJob::query()->where('business_id', $job->business_id)
            ->lockForUpdate()->findOrFail($job->id);
    }

    private function assertOpen(PackingJob $job): void
    {
        abort_unless(in_array($job->status, ['open', 'in_progress'], true), 422,
            'This packing job is closed. No more entries can be added.');
    }

    private function enough(StockBatch $batch, BigDecimal $amount, string $field): void
    {
        if ($amount->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }
        if ($amount->isGreaterThan(app(StockAvailability::class)->available($batch->business_id, $batch->id))) {
            throw ValidationException::withMessages([
                $field => 'Insufficient stock in batch '.$batch->batch_code.'.',
            ]);
        }
    }

    public function packCarton(PackingJob $job, array $data, int $userId): Carton
    {
        return DB::transaction(function () use ($job, $data, $userId) {
            $job = $this->lockJob($job);
            $existing = Carton::query()->where('business_id', $job->business_id)
                ->where('packing_key', $data['packing_key'])->first();
            if ($existing) {
                $prior = $existing->contents()->orderBy('batch_id')->get()->mapWithKeys(fn ($c) => [(int) $c->batch_id => (string) BigDecimal::of((string) $c->quantity)->strippedOfTrailingZeros()])->all();
                $incoming = [];
                foreach ($data['contents'] as $c) {
                    $incoming[(int) $c['batch_id']] = (string) BigDecimal::of((string) $c['quantity'])->strippedOfTrailingZeros();
                }ksort($incoming);
                ksort($prior);
                abort_unless($existing->packing_job_id === $job->id && $prior === $incoming && BigDecimal::of((string) $existing->net_weight_kg)->isEqualTo(BigDecimal::of((string) $data['net_weight_kg'])) && BigDecimal::of((string) $existing->gross_weight_kg)->isEqualTo(BigDecimal::of((string) $data['gross_weight_kg'])), 409, 'Duplicate carton submission has different contents.');

                return $existing;
            }
            $this->assertOpen($job);

            $qtyByBatch = [];
            $total = BigDecimal::zero();
            foreach ($data['contents'] as $line) {
                $batchId = (int) $line['batch_id'];
                if (isset($qtyByBatch[$batchId])) {
                    throw ValidationException::withMessages([
                        'contents' => 'Include each source batch only once per carton.',
                    ]);
                }
                $quantity = BigDecimal::of((string) $line['quantity']);
                if ($quantity->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['contents' => 'All quantities must be positive.']);
                }
                $qtyByBatch[$batchId] = $quantity;
                $total = $total->plus($quantity);
            }
            if (count($qtyByBatch) === 0) {
                throw ValidationException::withMessages(['contents' => 'Select at least one batch.']);
            }

            $net = BigDecimal::of((string) $data['net_weight_kg']);
            $gross = BigDecimal::of((string) $data['gross_weight_kg']);
            if ($net->isLessThanOrEqualTo(0) || $gross->isLessThan($net)) {
                throw ValidationException::withMessages([
                    'gross_weight_kg' => 'Gross weight must be at least the positive net weight.',
                ]);
            }
            if ($job->product->base_unit === 'kg' && ! $net->isEqualTo($total)) {
                throw ValidationException::withMessages([
                    'net_weight_kg' => 'For kilogram-based produce, net weight must equal packed kilograms.',
                ]);
            }

            // Lock source batches in deterministic order, guarding against simultaneous jobs.
            $batches = StockBatch::query()->where('business_id', $job->business_id)
                ->where('product_id', $job->product_id)
                ->whereIn('id', array_keys($qtyByBatch))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($batches->count() === count($qtyByBatch), 404);

            if ($job->sales_order_line_id) {
                $line = app(OrderSourcing::class)->line($job->business_id, $job->sales_order_line_id);
                $packed = BigDecimal::of(app(OrderSourcing::class)->summary($line)['packed']);
                if ($packed->plus($total)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                    throw ValidationException::withMessages(['contents' => 'Packed output exceeds the order quantity.']);
                }
            }
            foreach ($qtyByBatch as $batchId => $quantity) {
                if ($batches[$batchId]->is_quarantined) {
                    throw ValidationException::withMessages(['contents' => 'Batch '.$batches[$batchId]->batch_code.' is on a quality hold and cannot be packed.']);
                }
                $this->enough($batches[$batchId], $quantity, 'contents');
                app(OrderSourcing::class)->consume($job, $batches[$batchId], $quantity, false);
            }

            $carton = Carton::create([
                'business_id' => $job->business_id,
                'packing_job_id' => $job->id,
                'carton_code' => 'CTN-'.Str::upper((string) Str::ulid()),
                'packing_key' => $data['packing_key'],
                'net_weight_kg' => (string) $net,
                'gross_weight_kg' => (string) $gross,
                'tare_weight_kg' => (string) $gross->minus($net),
                'grade' => $data['grade'] ?? null,
                'packaging_type' => $data['packaging_type'] ?? null,
                'status' => 'packed',
                'packed_by' => $userId,
                'packed_at' => now(),
            ]);
            foreach ($qtyByBatch as $batchId => $quantity) {
                $contents = CartonContent::create([
                    'business_id' => $job->business_id,
                    'carton_id' => $carton->id,
                    'batch_id' => $batchId,
                    'quantity' => (string) $quantity,
                ]);
                StockMovement::create([
                    'business_id' => $job->business_id,
                    'batch_id' => $batchId,
                    'movement_type' => 'packing_consumption',
                    'quantity' => (string) $quantity->negated(),
                    'unit' => $job->product->base_unit,
                    'source_type' => 'carton_content',
                    'source_id' => $contents->id,
                    'created_by' => $userId,
                ]);
            }

            QrIdentity::firstOrCreate(
                ['business_id' => $job->business_id, 'level' => 'carton', 'record_id' => $carton->id],
                ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
            );
            if ($job->status === 'open') {
                $job->update(['status' => 'in_progress']);
            }

            return $carton->refresh();
        }, 3);
    }

    public function recordWaste(PackingJob $job, array $data, int $userId): PackingWasteEntry
    {
        return DB::transaction(function () use ($job, $data, $userId) {
            $job = $this->lockJob($job);
            $existing = PackingWasteEntry::query()->where('business_id', $job->business_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->packing_job_id === $job->id, 409, 'Duplicate grading submission.');

                return $existing;
            }
            $this->assertOpen($job);
            $batch = StockBatch::query()->where('business_id', $job->business_id)
                ->where('product_id', $job->product_id)
                ->lockForUpdate()->findOrFail((int) $data['batch_id']);
            $qty = BigDecimal::of((string) $data['quantity']);
            $this->enough($batch, $qty, 'quantity');
            app(OrderSourcing::class)->consume($job, $batch, $qty, true);
            $entry = PackingWasteEntry::create([
                'business_id' => $job->business_id,
                'packing_job_id' => $job->id,
                'batch_id' => $batch->id,
                'quantity' => (string) $qty,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $userId,
            ]);
            StockMovement::create([
                'business_id' => $job->business_id,
                'batch_id' => $batch->id,
                'movement_type' => 'grading_loss',
                'quantity' => (string) $qty->negated(),
                'unit' => $job->product->base_unit,
                'source_type' => 'packing_waste',
                'source_id' => $entry->id,
                'created_by' => $userId,
            ]);
            if ($job->status === 'open') {
                $job->update(['status' => 'in_progress']);
            }

            return $entry;
        }, 3);
    }

    public function complete(PackingJob $job, int $userId): PackingJob
    {
        return DB::transaction(function () use ($job, $userId) {
            $job = $this->lockJob($job);
            $this->assertOpen($job);
            if (! $job->cartons()->exists()) {
                throw ValidationException::withMessages([
                    'packing' => 'Pack at least one carton before completing the job.',
                ]);
            }
            $job->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $userId]);

            return $job;
        });
    }
}
