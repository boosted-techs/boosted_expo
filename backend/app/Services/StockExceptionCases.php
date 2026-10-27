<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Carton;
use App\Models\FinanceCostLayer;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceTradeCredit;
use App\Models\FinanceWorkflow;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StaffPerson;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockBatchDepletion;
use App\Models\StockBatchOrigin;
use App\Models\StockExceptionCase;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StockExceptionCases
{
    public const KINDS = ['location_split', 'partial_repack', 'customer_return', 'supplier_return', 'quality_claim', 'recall'];

    public function lineage(int $businessId, int $batchId): array
    {
        $ids = [$batchId];
        $front = [$batchId];
        for ($depth = 0; $front && $depth < 100; $depth++) {
            $next = StockBatchOrigin::query()->where('business_id', $businessId)->whereIn('source_batch_id', $front)->whereNotIn('batch_id', $ids)->pluck('batch_id')->all();
            $ids = [...$ids, ...$next];
            $front = $next;
        }abort_if($front !== [], 422, 'Stock lineage is too deep; review source identity before proceeding.');

        return $ids;
    }

    public function ancestors(int $businessId, int $batchId): array
    {
        $ids = [$batchId];
        $current = $batchId;
        for ($depth = 0; $depth < 100; $depth++) {
            $parent = StockBatchOrigin::query()->where('business_id', $businessId)->where('batch_id', $current)->value('source_batch_id');
            if (! $parent) {
                return $ids;
            }abort_if(in_array((int) $parent, $ids, true), 422, 'A stock-origin cycle needs correction.');
            $ids[] = (int) $parent;
            $current = (int) $parent;
        }abort(422, 'Stock origin lineage is too deep.');
    }

    public function affected(Business $b, int $batchId): array
    {
        $ids = $this->lineage($b->id, $batchId);
        $cartons = Carton::query()->where('business_id', $b->id)->whereHas('contents', fn ($q) => $q->where('business_id', $b->id)->whereIn('batch_id', $ids))->with('shipmentAllocation.shipment.customer')->orderBy('id')->get();
        $rows = [];
        foreach ($cartons as $carton) {
            $shipment = $carton->shipmentAllocation?->shipment;
            $rows[] = ['carton_id' => $carton->id, 'carton' => $carton->carton_code, 'status' => $carton->status, 'shipment_id' => $shipment?->id, 'shipment' => $shipment?->reference, 'customer_id' => $shipment?->customer_id, 'customer' => $shipment?->customer?->name];
        }

        return ['batch_ids' => $ids, 'cartons' => $rows];
    }

    private function source(Business $b, string $kind, array $data, ?int $exceptCase = null): array
    {
        if ($kind === 'partial_repack') {
            $carton = Carton::query()->where('business_id', $b->id)->with('contents', 'packingJob')->findOrFail($data['carton_id']);
            abort_unless($carton->status === 'packed' && ! $carton->shipmentAllocation()->exists(), 422, 'Unallocate the carton before a partial repack; dispatched goods need a customer return.');
            $parts = [];
            $release = BigDecimal::zero();
            foreach ($carton->contents->sortBy('batch_id') as $row) {
                $value = BigDecimal::of((string) ($data['release'][$row->batch_id] ?? '0'));
                abort_unless(! $value->isLessThan(0) && ! $value->isGreaterThan((string) $row->quantity), 422, 'Released quantity exceeds the original carton content.');
                $release = $release->plus($value);
                $parts[] = $row->only(['batch_id', 'quantity']);
            }abort_unless($release->isGreaterThan(0) && $release->isLessThan(app(ProcurementMath::class)->sum($carton->contents->pluck('quantity')->all())), 422, 'Release part of the carton. Use full unpack when no retained carton remains.');

            return ['carton' => $carton->only(['id', 'business_id', 'packing_job_id', 'net_weight_kg', 'gross_weight_kg', 'status', 'stock_location_id']), 'contents' => $parts, 'job' => $carton->packingJob->only(['id', 'status', 'sales_order_line_id', 'completed_at'])];
        }
        $batch = StockBatch::query()->where('business_id', $b->id)->with('product')->findOrFail($data['batch_id']);
        if (in_array($kind, ['quality_claim', 'recall'], true)) {
            return $this->affected($b, $batch->id);
        }
        if ($kind === 'customer_return') {
            $carton = Carton::query()->where('business_id', $b->id)->with('shipmentAllocation.shipment')->findOrFail($data['carton_id']);
            $shipment = $carton->shipmentAllocation?->shipment;
            abort_unless($shipment && in_array($shipment->status, ['dispatched', 'delivered'], true), 422, 'Choose the original dispatched carton.');
            $content = $carton->contents()->where('business_id', $b->id)->where('batch_id', $batch->id)->firstOrFail();
            $returned = BigDecimal::zero();
            foreach (StockExceptionCase::query()->where('business_id', $b->id)->where('kind', 'customer_return')->where('carton_id', $carton->id)->where('batch_id', $batch->id)->whereNotIn('status', ['rejected', 'withdrawn'])->when($exceptCase, fn ($q) => $q->whereKeyNot($exceptCase))->get() as $prior) {
                if (! $prior->workflow_id || $prior->workflow?->posting_status !== 'rejected') {
                    $returned = $returned->plus((string) $prior->quantity);
                }
            }$qty = BigDecimal::of($data['quantity']);
            abort_unless($qty->isGreaterThan(0) && ! $returned->plus($qty)->isGreaterThan((string) $content->quantity), 422, 'Returns exceed the quantity actually dispatched in this carton.');

            return ['batch_id' => $batch->id, 'carton_id' => $carton->id, 'shipment_id' => $shipment->id, 'customer_id' => $shipment->customer_id, 'content_quantity' => (string) $content->quantity, 'product_id' => $batch->product_id, 'unit' => $batch->product->base_unit, 'dispatched_at' => $shipment->actual_departure_at?->toDateString()];
        }
        abort_if(StockAdjustment::query()->where('business_id', $b->id)->where('batch_id', $batch->id)->where('posting_status', 'not_posted')->whereIn('status', ['pending', 'approved'])->exists(), 422, 'Finish pending counts before splitting or returning loose stock.');
        foreach (FinanceInventoryRelief::query()->where('business_id', $b->id)->where('posting_status', 'not_posted')->cursor() as $pending) {
            abort_if(collect($pending->data_snapshot['lines'] ?? [])->contains(fn ($line) => (int) $line['batch_id'] === $batch->id), 422, 'Finish pending inventory valuation before changing this batch.');
        }
        abort_if(FinanceCostLayer::query()->where('business_id', $b->id)->where('batch_id', $batch->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted'))->exists(), 422, 'Finish the pending batch cost before splitting or returning stock.');
        $qty = BigDecimal::of($data['quantity']);
        abort_unless($qty->isGreaterThan(0) && ! $qty->isGreaterThan(app(OrderSourcing::class)->free($batch)), 422, 'Only loose, unreserved quantity may move. Release affected order reservations first.');
        $basis = app(FinanceCostBasis::class)->batchBasis($batch);
        $cost = app(FinanceMath::class)->money($qty->multipliedBy($basis['unit']));
        abort_unless($cost->isGreaterThan(0), 422, 'This quantity is below ledger precision.');

        return ['batch_id' => $batch->id, 'product_id' => $batch->product_id, 'unit' => $batch->product->base_unit, 'fingerprint' => app(StockExceptions::class)->fingerprint($batch), 'basis_quantity' => (string) $basis['quantity'], 'basis_cost' => (string) $basis['cost'], 'base_cost' => (string) $cost, 'quarantined' => $batch->is_quarantined];
    }

    public function prepare(Business $b, string $kind, array $data, int $actor): StockExceptionCase
    {
        return DB::transaction(function () use ($b, $kind, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'procurement');
            abort_unless(in_array($kind, self::KINDS, true), 422);
            ksort($data);
            $hash = app(FinanceWorkflows::class)->hash([$kind, $data]);
            $prior = StockExceptionCase::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_hash, $hash), 422, 'This exception request already has different details.');

                return $prior;
            }
            abort_unless($data['event_on'] <= now($b->timezone)->toDateString(), 422, 'Use an actual event date.');
            if (in_array($kind, ['location_split', 'partial_repack'], true)) {
                abort_unless($data['event_on'] === now($b->timezone)->toDateString(), 422, 'Record current physical split/repack observations today.');
            }
            if (! empty($data['staff_person_id'])) {
                StaffPerson::query()->where('business_id', $b->id)->findOrFail($data['staff_person_id']);
            }if (! empty($data['to_location_id'])) {
                StockLocation::query()->where('business_id', $b->id)->where('active', true)->findOrFail($data['to_location_id']);
            }
            $source = $this->source($b, $kind, $data);
            if ($kind === 'customer_return' && ! empty($source['dispatched_at'])) {
                abort_unless($data['event_on'] >= $source['dispatched_at'], 422, 'A customer return cannot precede dispatch.');
            }$snapshot = ['kind' => $kind, 'input' => $data, 'source' => $source];
            $case = StockExceptionCase::create(['due_on' => $data['due_on'] ?? null, 'business_id' => $b->id, 'reference' => 'CASE-'.Str::upper((string) Str::ulid()), 'kind' => $kind, 'event_on' => $data['event_on'], 'batch_id' => $data['batch_id'] ?? null, 'carton_id' => $data['carton_id'] ?? null, 'shipment_id' => $source['shipment_id'] ?? null, 'staff_person_id' => $data['staff_person_id'] ?? null, 'quantity' => $data['quantity'] ?? null, 'status' => in_array($kind, ['quality_claim', 'recall'], true) ? 'open' : 'pending', 'reason' => $data['reason'], 'external_reference' => $data['external_reference'], 'data_snapshot' => $snapshot, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($snapshot), 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
            if ($kind === 'recall') {
                foreach ($source['batch_ids'] as $id) {
                    $batch = StockBatch::query()->where('business_id', $b->id)->findOrFail($id);
                    if (! $batch->is_quarantined) {
                        app(StockQualityControls::class)->change($b, $id, true, 'Recall '.$case->reference.': '.$data['reason'], $actor);
                    }
                }
            }
            $this->event($case, 'created', $data['reason'], $actor);

            return $case;
        }, 3);
    }

    public function verify(StockExceptionCase $case): array
    {
        abort_unless(hash_equals($case->snapshot_hash, app(FinanceWorkflows::class)->hash($case->data_snapshot)), 422, 'Original exception evidence changed.');
        $d = $case->data_snapshot;
        abort_unless($case->kind === $d['kind'] && $case->event_on->toDateString() === $d['input']['event_on'] && $case->reason === $d['input']['reason'] && $case->external_reference === $d['input']['external_reference'] && (string) $case->batch_id === (string) ($d['input']['batch_id'] ?? '') && (string) $case->carton_id === (string) ($d['input']['carton_id'] ?? '') && ($case->quantity === null || BigDecimal::of((string) $case->quantity)->isEqualTo($d['input']['quantity'])), 422, 'A frozen exception source field changed.');

        return $d;
    }

    public function review(Business $b, StockExceptionCase $case, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $case, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'procurement');
            $case = StockExceptionCase::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($case->id);
            $d = $this->verify($case);
            abort_unless($case->status === 'pending' && $case->created_by !== $actor, 422, 'A different authorised person must review a pending physical exception.');
            if (! $approve) {
                $case->update(['status' => 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
                $this->event($case, 'rejected', $note, $actor);

                return;
            }
            abort_unless(app(FinanceWorkflows::class)->hash($this->source($b, $case->kind, $d['input'], $case->id)) === app(FinanceWorkflows::class)->hash($d['source']), 422, 'Stock, reservations, carton contents or their cost changed. Withdraw and prepare the physical exception again.');
            $result = [];
            $status = 'completed';
            if ($case->kind === 'location_split') {
                $source = StockBatch::query()->where('business_id', $b->id)->findOrFail($case->batch_id);
                $to = StockLocation::query()->where('business_id', $b->id)->where('active', true)->findOrFail($d['input']['to_location_id']);
                abort_unless($to->id !== $source->stock_location_id, 422, 'Choose another physical location.');
                $batch = $this->newBatch($b, $case, $d['source']['product_id'], $to->id, (bool) $source->is_quarantined);
                $cost = $d['source']['base_cost'];
                $this->origin($case, $batch, $case->quantity, $cost, 'location_split', $source->id, null, $d['source']);
                StockBatchDepletion::create(['business_id' => $b->id, 'batch_id' => $source->id, 'case_id' => $case->id, 'kind' => 'location_split', 'quantity' => $case->quantity, 'base_cost' => $cost]);
                $this->movement($case, $source->id, BigDecimal::of((string) $case->quantity)->negated(), 'location_split', $actor);
                $this->movement($case, $batch->id, BigDecimal::of((string) $case->quantity), 'location_split', $actor);
                $result = ['batch_id' => $batch->id, 'base_cost' => $cost];
            } elseif ($case->kind === 'partial_repack') {
                $carton = Carton::query()->where('business_id', $b->id)->with('packingJob')->findOrFail($case->carton_id);
                $unpack = app(StockExceptions::class)->requestRepack($b, ['carton_id' => $carton->id, 'reason' => $case->reason, 'request_key' => $case->request_key], $case->created_by);
                app(StockExceptions::class)->reviewRepack($unpack, true, $note, $actor);
                $contents = [];
                foreach ($d['source']['contents'] as $part) {
                    $keep = BigDecimal::of($part['quantity'])->minus((string) ($d['input']['release'][$part['batch_id']] ?? '0'));
                    if ($keep->isGreaterThan(0)) {
                        $contents[] = ['batch_id' => $part['batch_id'], 'quantity' => (string) $keep];
                    }
                }
                if ($carton->packingJob->sales_order_line_id) {
                    $line = SalesOrderLine::query()->where('business_id', $b->id)->findOrFail($carton->packingJob->sales_order_line_id);
                    foreach ($contents as $part) {
                        $available = BigDecimal::zero();
                        foreach (StockReservation::query()->where('business_id', $b->id)->where('sales_order_line_id', $line->id)->where('batch_id', $part['batch_id'])->whereNull('released_at')->get() as $reservation) {
                            $available = $available->plus((string) $reservation->quantity)->minus((string) $reservation->consumed_quantity)->minus((string) $reservation->lost_quantity);
                        }
                        $missing = BigDecimal::max('0', BigDecimal::of($part['quantity'])->minus($available));
                        if ($missing->isGreaterThan(0)) {
                            app(OrderSourcing::class)->reserve($line, StockBatch::query()->where('business_id', $b->id)->findOrFail($part['batch_id']), ['quantity' => (string) $missing, 'request_key' => (string) Str::uuid(), 'reason' => 'Retained replacement contents for '.$case->reference.' after controlled unpack.'], $actor);
                        }
                    }
                }
                $replacement = app(PackingOperations::class)->packCarton($carton->packingJob->fresh(), ['packing_key' => $case->request_key, 'net_weight_kg' => $d['input']['net_weight_kg'], 'gross_weight_kg' => $d['input']['gross_weight_kg'], 'contents' => $contents], $actor);
                $result = ['old_carton_id' => $carton->id, 'retained_carton_id' => $replacement->id, 'released' => $d['input']['release']];
            } else {
                $flow = $this->prepareReturn($b, $case, $d);
                $case->workflow_id = $flow->id;
                $result = ['workflow_id' => $flow->id];
                $status = 'finance_pending';
            }
            $case->fill(['status' => $status, 'result' => $result, 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->event($case, 'reviewed', $note, $actor);
        }, 3);
    }

    private function newBatch(Business $b, StockExceptionCase $case, int $productId, int $locationId, bool $hold): StockBatch
    {
        return StockBatch::create(['business_id' => $b->id, 'product_id' => $productId, 'batch_code' => 'EX-'.Str::upper((string) Str::ulid()), 'source_reference' => $case->reference, 'received_on' => $case->event_on->toDateString(), 'stock_location_id' => $locationId, 'is_quarantined' => $hold, 'quality_hold_reason' => $hold ? 'Return / source hold: '.$case->reference : null, 'quality_hold_by' => $hold ? $case->created_by : null, 'quality_held_at' => $hold ? now() : null]);
    }

    private function origin(StockExceptionCase $case, StockBatch $batch, string $quantity, string $cost, string $kind, ?int $parent, ?int $workflow, array $source): void
    {
        $proof = ['batch_id' => $batch->id, 'quantity' => $quantity, 'base_cost' => $cost, 'kind' => $kind, 'case_id' => $case->id, 'source' => $source];
        StockBatchOrigin::create(['business_id' => $case->business_id, 'batch_id' => $batch->id, 'case_id' => $case->id, 'source_batch_id' => $parent, 'workflow_id' => $workflow, 'kind' => $kind, 'quantity' => $quantity, 'base_cost' => $cost, 'data_snapshot' => $proof, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($proof)]);
    }

    private function movement(StockExceptionCase $case, int $batch, BigDecimal $qty, string $kind, int $actor): void
    {
        StockMovement::create(['occurred_on' => $case->event_on->toDateString(), 'business_id' => $case->business_id, 'batch_id' => $batch, 'movement_type' => $kind, 'quantity' => (string) $qty, 'unit' => $case->data_snapshot['source']['unit'] ?? StockBatch::findOrFail($batch)->product->base_unit, 'source_type' => 'stock_exception', 'source_id' => $case->id, 'created_by' => $actor]);
    }

    private function returnValue(Business $b, StockExceptionCase $case, array $d): array
    {
        if ($case->kind === 'supplier_return') {
            return ['base_cost' => $d['source']['base_cost'], 'basis_hash' => app(FinanceWorkflows::class)->hash($d['source'])];
        }
        $relief = FinanceInventoryRelief::query()->where('business_id', $b->id)->where('shipment_id', $case->shipment_id)->where('posting_status', 'posted')->first();
        abort_unless($relief, 422, 'Post the original shipment COGS before accepting a financial return value. The physical return observation remains recorded.');
        $line = collect($relief->data_snapshot['lines'])->firstWhere('batch_id', $case->batch_id);
        abort_unless($line, 422, 'The original shipment cost has no matching source batch.');
        $journal = FinanceJournal::query()->where('business_id', $b->id)->where('source_type', 'inventory_relief')->where('source_id', $relief->id)->firstOrFail();
        $prior = StockExceptionCase::query()->where('business_id', $b->id)->where('shipment_id', $case->shipment_id)->where('batch_id', $case->batch_id)->where('kind', 'customer_return')->where('id', '<', $case->id)->whereNotIn('status', ['rejected', 'withdrawn'])->orderBy('id')->get();
        $proof = $prior->map(fn ($r) => [$r->id, (string) $r->quantity, $r->snapshot_hash])->all();
        $expense = $relief->data_snapshot['expense_account'] ?? 'cogs';
        $layers = [];
        $share = function (string $cost, string $quantity, array $exclude, string $date) use ($case, $prior, &$layers, $expense): void {
            $used = BigDecimal::zero();
            foreach ($prior as $r) {
                if (! in_array($r->id, $exclude, true)) {
                    $used = $used->plus((string) $r->quantity);
                }
            }
            $next = $used->plus((string) $case->quantity);
            abort_unless(! $next->isGreaterThan($quantity), 422, 'Returned units exceed this original cost layer.');
            $math = app(FinanceMath::class);
            $allocated = fn (BigDecimal $q) => $q->isEqualTo($quantity) ? BigDecimal::of($cost) : $math->money(BigDecimal::of($cost)->multipliedBy($q)->dividedBy($quantity, 14, RoundingMode::HalfUp));
            $value = $allocated($next)->minus($allocated($used));
            if (! $value->isZero()) {
                $layers[] = ['event_on' => max($case->event_on->toDateString(), $date), 'base_cost' => (string) $value, 'expense_account' => $expense];
            }
        };
        $share($line['base_cost'], $line['quantity'], [], $journal->posted_on->toDateString());
        $added = DB::table('finance_cost_layer_reliefs as r')->join('finance_cost_layers as l', 'l.id', '=', 'r.cost_layer_id')->join('finance_workflows as w', 'w.id', '=', 'l.workflow_id')->where('r.business_id', $b->id)->where('l.business_id', $b->id)->where('l.batch_id', $case->batch_id)->where('r.inventory_relief_id', $relief->id)->where('w.posting_status', 'posted')->orderBy('r.id')->get(['r.*', 'w.data_snapshot', 'w.snapshot_hash']);
        foreach ($added as $part) {
            $snapshot = json_decode($part->data_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $share((string) $part->base_cost, (string) $part->quantity_covered, $snapshot['details']['returned_case_ids'] ?? [], max($journal->posted_on->toDateString(), $part->event_on));
        }
        $cost = BigDecimal::zero();
        foreach ($layers as $layer) {
            $cost = $cost->plus($layer['base_cost']);
        }

        return ['base_cost' => (string) $cost, 'relief_id' => $relief->id, 'layers' => $layers, 'basis_hash' => app(FinanceWorkflows::class)->hash([$relief->sha256, $added->toArray(), $proof])];
    }

    private function prepareReturn(Business $b, StockExceptionCase $case, array $d): FinanceWorkflow
    {
        $v = $this->returnValue($b, $case, $d);
        $cost = $v['base_cost'];
        abort_unless(BigDecimal::of($cost)->isGreaterThan(0), 422, 'Return cost is below ledger precision.');
        $l = app(FinanceLedger::class);
        $legs = [];
        if ($case->kind === 'customer_return') {
            foreach ($v['layers'] as $layer) {
                $signed = BigDecimal::of($layer['base_cost']);
                $part = (string) $signed->abs();
                $legs[] = ['currency' => $b->base_currency, 'rate_to_base' => '1', 'event_on' => $layer['event_on'], 'amount' => $part, 'lines' => [$l->line('inventory', $signed->isGreaterThan(0) ? $part : '0', $signed->isGreaterThan(0) ? '0' : $part, $signed->isGreaterThan(0) ? $part : '0', $signed->isGreaterThan(0) ? '0' : $part, 'Returned goods at their original shipped cost'), $l->line($layer['expense_account'], $signed->isGreaterThan(0) ? '0' : $part, $signed->isGreaterThan(0) ? $part : '0', $signed->isGreaterThan(0) ? '0' : $part, $signed->isGreaterThan(0) ? $part : '0', 'Restore original cost of returned goods')]];
            }
        } else {
            $legs[] = ['currency' => $b->base_currency, 'rate_to_base' => '1', 'amount' => $cost, 'lines' => [$l->line('supplier_return_pending', $cost, '0', $cost, '0', 'Goods returned; supplier claim awaits its separately agreed credit'), $l->line('inventory', '0', $cost, '0', $cost, 'Actual goods returned to supplier')]];
        }
        $data = ['event_on' => $case->event_on->toDateString(), 'reason' => $case->reason, 'external_reference' => $case->external_reference, 'request_key' => $case->request_key];

        return app(FinanceWorkflows::class)->create($b, 'goods_return', $data, $case->created_by, $legs, ['case_id' => $case->id, 'case_hash' => $case->snapshot_hash, 'value' => $v]);
    }

    public function validateReturn(FinanceWorkflow $flow): void
    {
        $case = StockExceptionCase::query()->where('business_id', $flow->business_id)->findOrFail($flow->data_snapshot['details']['case_id']);
        $d = $this->verify($case);
        abort_unless($case->status === 'finance_pending' && $case->workflow_id === $flow->id && $case->snapshot_hash === $flow->data_snapshot['details']['case_hash'], 422, 'The approved physical return changed.');
        if ($case->kind === 'customer_return') {
            abort_if(StockExceptionCase::query()->where('business_id', $case->business_id)->where('shipment_id', $case->shipment_id)->where('batch_id', $case->batch_id)->where('kind', 'customer_return')->where('id', '<', $case->id)->whereNotIn('status', ['rejected', 'withdrawn'])->where(fn ($q) => $q->whereNull('workflow_id')->orWhereHas('workflow', fn ($q) => $q->where('posting_status', '!=', 'posted')))->exists(), 422, 'Finish or reject the earlier return for this shipment and batch first.');
        }
        $b = Business::findOrFail($flow->business_id);
        abort_unless(app(FinanceWorkflows::class)->hash($this->source($b, $case->kind, $d['input'], $case->id)) === app(FinanceWorkflows::class)->hash($d['source']) && $this->returnValue($b, $case, $d) === $flow->data_snapshot['details']['value'], 422, 'Original stock, return capacity or cost changed. Withdraw and review the return again.');
    }

    public function finalizeReturn(FinanceWorkflow $flow, int $actor): void
    {
        $case = StockExceptionCase::query()->where('business_id', $flow->business_id)->findOrFail($flow->data_snapshot['details']['case_id']);
        $d = $case->data_snapshot;
        $cost = $flow->data_snapshot['details']['value']['base_cost'];
        $result = $case->result ?? [];
        if ($case->kind === 'customer_return') {
            $location = StockLocation::query()->where('business_id', $flow->business_id)->where('active', true)->findOrFail($d['input']['to_location_id']);
            $batch = $this->newBatch(Business::findOrFail($flow->business_id), $case, $d['source']['product_id'], $location->id, true);
            $this->origin($case, $batch, (string) $case->quantity, $cost, 'customer_return', $case->batch_id, $flow->id, [...$d['source'], 'valuation_layers' => $flow->data_snapshot['details']['value']['layers'] ?? []]);
            $this->movement($case, $batch->id, BigDecimal::of((string) $case->quantity), 'customer_return', $actor);
            $result['returned_batch_id'] = $batch->id;
        } else {
            StockBatchDepletion::create(['business_id' => $flow->business_id, 'batch_id' => $case->batch_id, 'case_id' => $case->id, 'workflow_id' => $flow->id, 'kind' => 'supplier_return', 'quantity' => $case->quantity, 'base_cost' => $cost]);
            $this->movement($case, $case->batch_id, BigDecimal::of((string) $case->quantity)->negated(), 'supplier_return', $actor);
        }
        $case->update(['status' => 'awaiting_disposition', 'result' => $result]);
        $this->event($case, 'physical_return_posted', 'Quantity and original cost were posted together. Credit, replacement or loss disposition is still required.', $actor);
    }

    public function event(StockExceptionCase $case, string $action, string $note, int $actor, ?int $shipment = null): void
    {
        DB::table('stock_exception_events')->insert(['business_id' => $case->business_id, 'case_id' => $case->id, 'action' => $action, 'note' => $note, 'created_by' => $actor, 'shipment_id' => $shipment, 'created_at' => now(), 'updated_at' => now()]);
        app(PlatformAccess::class)->audit($case->business_id, $actor, 'stock.exception_'.$action, $note, [], ['case_id' => $case->id, 'shipment_id' => $shipment]);
    }

    public function progress(Business $b, StockExceptionCase $case, array $data, int $actor): void
    {
        DB::transaction(function () use ($b, $case, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'procurement');
            $case = StockExceptionCase::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($case->id);
            $this->verify($case);
            if (! empty($data['shipment_id'])) {
                $ids = collect([...($case->data_snapshot['source']['cartons'] ?? []), ...($case->batch_id ? $this->affected($b, $case->batch_id)['cartons'] : [])])->pluck('shipment_id')->filter()->unique()->all();
                abort_unless(in_array((int) $data['shipment_id'], $ids, true), 422, 'Record follow-up only for a shipment affected by this case.');
            }
            abort_unless(! isset($data['due_on']) || ! in_array($case->status, ['closed', 'completed', 'rejected', 'withdrawn'], true) || $data['action'] === 'reopen', 422, 'Reopen the case before changing its deadline.');
            if (! empty($data['due_on'])) {
                app(PlatformAccess::class)->audit($b->id, $actor, 'stock.case_deadline_changed', $data['note'], ['case_id' => $case->id, 'due_on' => $case->due_on?->toDateString()], ['due_on' => $data['due_on']]);
                $case->update(['due_on' => $data['due_on']]);
            }
            if (! empty($data['staff_person_id'])) {
                StaffPerson::query()->where('business_id', $b->id)->findOrFail($data['staff_person_id']);
                $case->update(['staff_person_id' => (int) $data['staff_person_id']]);
            }
            if ($data['action'] === 'reopen') {
                abort_unless($case->status === 'closed' && $case->created_by !== $actor && in_array($case->kind, ['quality_claim', 'recall', 'customer_return', 'supplier_return'], true), 422, 'A separate reviewer can reopen a closed return, claim or recall for further investigation.');
                $case->update(['status' => 'investigating']);
                $batchId = $case->kind === 'customer_return' ? ($case->result['returned_batch_id'] ?? null) : $case->batch_id;
                if ($batchId && in_array($case->kind, ['recall', 'customer_return'], true)) {
                    foreach ($this->lineage($b->id, $batchId) as $id) {
                        $batch = StockBatch::query()->where('business_id', $b->id)->findOrFail($id);
                        if (! $batch->is_quarantined) {
                            app(StockQualityControls::class)->change($b, $id, true, $data['note'], $actor);
                        }
                    }
                }
            } elseif ($data['action'] === 'withdraw') {
                abort_unless($case->created_by === $actor && $case->status === 'pending', 422, 'Only the observer may withdraw a pending physical request.');
                $case->update(['status' => 'withdrawn']);
            } elseif ($data['action'] === 'close') {
                $cycleStart = (int) DB::table('stock_exception_events')->where('business_id', $b->id)->where('case_id', $case->id)->where('action', 'reopen')->max('id');
                $disk = Storage::disk('local');
                foreach (DB::table('stock_case_evidence')->where('business_id', $b->id)->where('case_id', $case->id)->get() as $file) {
                    abort_unless(str_starts_with($file->path, 'stock-case-evidence/'.$b->id.'/') && $disk->exists($file->path) && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path))), 422, 'Original case evidence is missing or changed. Investigate before closing.');
                }

                if (SalesOrder::query()->where('business_id', $b->id)->where('replacement_case_id', $case->id)->exists()) {
                    app(ReplacementOrders::class)->assertDelivered($case);
                }
                abort_if(FinanceTradeCredit::query()->where('business_id', $b->id)->where('case_id', $case->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted'))->exists(), 422, 'Finish or withdraw pending claim credits before closing this case.');
                if ($case->kind === 'supplier_return') {
                    abort_unless($case->resolution_workflow_id && FinanceWorkflow::query()->where('business_id', $b->id)->whereKey($case->resolution_workflow_id)->where('posting_status', 'posted')->exists(), 422, 'Post the agreed supplier credit or reviewed claim loss before closing the return.');
                }
                if ($case->kind === 'customer_return') {
                    abort_unless(DB::table('stock_exception_events')->where('business_id', $b->id)->where('case_id', $case->id)->where('action', 'disposition_recorded')->where('id', '>', $cycleStart)->exists(), 422, 'Record an inspected stock disposition before closing this customer return.');
                }
                abort_unless($case->created_by !== $actor && in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'A separate reviewer closes a resolved operational case.');
                if ($case->kind === 'recall') {
                    $ids = collect([...$case->data_snapshot['source']['cartons'], ...$this->affected($b, $case->batch_id)['cartons']])->pluck('shipment_id')->filter()->unique();
                    foreach ($ids as $id) {
                        abort_unless(DB::table('stock_exception_events')->where('business_id', $b->id)->where('case_id', $case->id)->where('shipment_id', $id)->whereIn('action', ['contact_recorded', 'scope_excluded'])->where('id', '>', $cycleStart)->exists(), 422, 'Record contact evidence or a justified scope exclusion for every affected shipment first.');
                    }
                }$case->update(['status' => 'closed']);
            } elseif ($data['action'] === 'disposition_recorded' && $case->kind === 'customer_return') {
                abort_unless(in_array($case->status, ['awaiting_disposition', 'investigating'], true), 422, 'Post the actual goods return before recording its stock disposition.');
                $batch = StockBatch::query()->where('business_id', $b->id)->findOrFail($case->result['returned_batch_id'] ?? 0);
                $disposition = $data['disposition'] ?? null;
                if ($disposition === 'restock') {
                    abort_unless(app(StockAvailability::class)->available($b->id, $batch->id)->isGreaterThan(0), 422, 'No returned loose goods remain for restocking.');
                    if ($batch->is_quarantined) {
                        app(StockQualityControls::class)->change($b, $batch->id, false, $data['note'], $actor);
                    }
                } elseif ($disposition === 'writeoff') {
                    abort_unless(app(StockAvailability::class)->available($b->id, $batch->id)->isZero(), 422, 'Record and post the physical loss/count and its cost first.');
                    $basis = app(FinanceCostBasis::class)->batchBasis($batch);
                    abort_unless($basis['quantity']->isZero() && $basis['cost']->isZero(), 422, 'The returned stock loss is not financially reconciled.');
                } elseif ($disposition === 'replacement') {
                    app(ReplacementOrders::class)->assertDelivered($case);
                } else {
                    abort(422, 'Choose inspected restock, a posted write-off, or a linked replacement outcome.');
                }
            } elseif ($data['action'] === 'investigate') {
                abort_unless(in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'This case is not open for investigation.');
                $case->update(['status' => 'investigating']);
            } else {
                abort_unless(in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'Add follow-up evidence to an open operational case.');
            }
            $this->event($case, $data['action'], $data['note'], $actor, isset($data['shipment_id']) ? (int) $data['shipment_id'] : null);
        }, 3);
    }
}
