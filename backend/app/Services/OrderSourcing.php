<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\MarketTrip;
use App\Models\OrderReceiptAllocation;
use App\Models\OrderSupply;
use App\Models\PackingJob;
use App\Models\PurchaseOrder;
use App\Models\SalesOrderLine;
use App\Models\StockBatch;
use App\Models\StockReservation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderSourcing
{
    private function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0));
    }

    private function sum(iterable $values): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($values as $v) {
            $sum = $sum->plus((string) $v);
        }

        return $sum;
    }

    private function fail(string $message, string $field = 'quantity'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function line(int $businessId, int $id): SalesOrderLine
    {
        $line = SalesOrderLine::query()->where('business_id', $businessId)->with('order')->findOrFail($id);
        abort_unless($line->order->status === 'confirmed', 422, 'This order is closed.');

        return $line;
    }

    public function reserved(int $businessId, int $batchId, ?int $exceptLine = null): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach (StockReservation::query()->where('business_id', $businessId)->where('batch_id', $batchId)->whereNull('released_at')->when($exceptLine, fn ($q) => $q->where('sales_order_line_id', '!=', $exceptLine))->get() as $r) {
            $sum = $sum->plus($this->decimal($r->quantity)->minus($r->consumed_quantity)->minus($r->lost_quantity));
        }

        return $sum;
    }

    public function free(StockBatch $batch, ?int $forLine = null): BigDecimal
    {
        return app(StockAvailability::class)->available($batch->business_id, $batch->id)->minus($this->reserved($batch->business_id, $batch->id, $forLine));
    }

    public function summary(SalesOrderLine $line): array
    {
        $packed = BigDecimal::zero();
        $loss = BigDecimal::zero();
        $jobs = PackingJob::query()->where('business_id', $line->business_id)->where('sales_order_line_id', $line->id)->with('cartons.contents', 'losses')->get();
        foreach ($jobs as $job) {
            foreach ($job->cartons->where('status', '!=', 'repacked') as $carton) {
                $packed = $packed->plus($this->sum($carton->contents->pluck('quantity')));
            } $loss = $loss->plus($this->sum($job->losses->pluck('quantity')));
        }
        $reserved = BigDecimal::zero();
        $held = BigDecimal::zero();
        foreach (StockReservation::query()->where('business_id', $line->business_id)->where('sales_order_line_id', $line->id)->whereNull('released_at')->with('batch')->get() as $r) {
            $remaining = $this->decimal($r->quantity)->minus($r->consumed_quantity)->minus($r->lost_quantity);
            if ($r->batch->is_quarantined) {
                $held = $held->plus($remaining);
            } else {
                $reserved = $reserved->plus($remaining);
            }
        }
        $outstanding = BigDecimal::zero();
        $market = BigDecimal::zero();
        $accepted = BigDecimal::zero();
        $rejected = BigDecimal::zero();
        $supplies = OrderSupply::query()->where('business_id', $line->business_id)->where('sales_order_line_id', $line->id)->with('purchase.supplier', 'trip.person')->get();
        foreach ($supplies as $s) {
            if ($s->kind === 'market') {
                $bought = $this->sum(OrderSupply::query()->where('business_id', $line->business_id)->where('origin_supply_id', $s->id)->pluck('quantity'));
                if ($s->status === 'active') {
                    $market = $market->plus($this->decimal($s->quantity)->minus($bought));
                }
            } else {
                $allocs = OrderReceiptAllocation::query()->where('business_id', $line->business_id)->where('order_supply_id', $s->id)->get();
                if ($s->status === 'active') {
                    $outstanding = $outstanding->plus($this->decimal($s->quantity)->minus($this->sum($allocs->pluck('received_quantity'))));
                }
                $accepted = $accepted->plus($this->sum($allocs->pluck('accepted_quantity')));
                $rejected = $rejected->plus($this->sum($allocs->pluck('rejected_quantity')));
            }
        }
        $covered = $packed->plus($reserved)->plus($outstanding)->plus($market);

        return ['ordered' => (string) $line->quantity, 'packed' => (string) $packed, 'loss' => (string) $loss, 'reserved' => (string) $reserved, 'quality_held' => (string) $held, 'expected' => (string) $outstanding, 'market_pending' => (string) $market, 'accepted' => (string) $accepted, 'rejected' => (string) $rejected, 'closed_quantity' => (string) ($line->closed_quantity ?? 0), 'shortage' => (string) BigDecimal::max(BigDecimal::zero(), $this->decimal($line->quantity)->minus($line->closed_quantity ?? 0)->minus($covered)), 'supplies' => $supplies, 'jobs' => $jobs];
    }

    public function reserve(SalesOrderLine $line, StockBatch $batch, array $data, int $actor, ?int $supplyId = null): StockReservation
    {
        return DB::transaction(function () use ($line, $batch, $data, $actor, $supplyId) {
            app(OrderOperations::class)->lockForWork($line->business_id, 'procurement');
            $line = $this->line($line->business_id, $line->id);
            $existing = StockReservation::query()->where('business_id', $line->business_id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->sales_order_line_id === $line->id && $existing->batch_id === $batch->id && $this->decimal($existing->quantity)->isEqualTo($this->decimal($data['quantity'])), 409, 'Reservation key already used for different stock.');

                return $existing;
            }
            $batch = StockBatch::query()->where('business_id', $line->business_id)->where('product_id', $line->product_id)->lockForUpdate()->findOrFail($batch->id);
            $qty = $this->decimal($data['quantity']);
            if (! $qty->isGreaterThan(0) || $batch->is_quarantined || $qty->isGreaterThan($this->free($batch))) {
                $this->fail('This batch is held or has insufficient free stock.');
            }
            if (! $supplyId && $qty->isGreaterThan($this->decimal($this->summary($line)['shortage']))) {
                $this->fail('Reserve only the uncovered order quantity. Release or reduce an existing commitment first.');
            }
            $r = StockReservation::create(['business_id' => $line->business_id, 'sales_order_line_id' => $line->id, 'batch_id' => $batch->id, 'order_supply_id' => $supplyId, 'quantity' => (string) $qty, 'reason' => $data['reason'], 'request_key' => $data['request_key'], 'created_by' => $actor]);
            app(OrderOperations::class)->event($line->order, $actor, 'stock.reserved', $data['reason'], ['reservation_id' => $r->id, 'quantity' => (string) $qty, 'batch_id' => $batch->id]);

            return $r;
        }, 3);
    }

    public function supply(SalesOrderLine $line, array $data, int $actor): OrderSupply
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            app(OrderOperations::class)->lockForWork($line->business_id, 'procurement');
            $line = $this->line($line->business_id, $line->id);
            $hash = hash('sha256', json_encode(collect($data)->except('request_key')->all(), JSON_THROW_ON_ERROR));
            $old = OrderSupply::query()->where('business_id', $line->business_id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash && $old->sales_order_line_id === $line->id, 409, 'Supply key already used for a different commitment.');

                return $old;
            }
            $qty = $this->decimal($data['quantity']);
            if (! $qty->isGreaterThan(0)) {
                $this->fail('Supply quantity must be positive.');
            }
            $purchase = null;
            $trip = null;
            $origin = null;
            if ($data['kind'] === 'purchase') {
                $purchase = PurchaseOrder::query()->where('business_id', $line->business_id)->where('product_id', $line->product_id)->whereIn('status', ['ordered', 'part_received'])->lockForUpdate()->findOrFail($data['purchase_order_id'] ?? 0);
                $remaining = $this->decimal($purchase->quantity)->minus($this->sum($purchase->receipts()->pluck('received_quantity')));
                $committed = BigDecimal::zero();
                foreach (OrderSupply::query()->where('business_id', $line->business_id)->where('purchase_order_id', $purchase->id)->where('status', 'active')->get() as $s) {
                    $committed = $committed->plus($this->decimal($s->quantity)->minus($this->sum(OrderReceiptAllocation::query()->where('order_supply_id', $s->id)->pluck('received_quantity'))));
                }
                if ($qty->isGreaterThan($remaining->minus($committed))) {
                    $this->fail('This purchase has insufficient unreceived and unallocated quantity. Reserve already-received batches separately.');
                }
                if (! empty($data['origin_supply_id'])) {
                    $origin = OrderSupply::query()->where('business_id', $line->business_id)->where('sales_order_line_id', $line->id)->where('kind', 'market')->where('status', 'active')->lockForUpdate()->findOrFail($data['origin_supply_id']);
                    abort_unless($purchase->market_trip_id === $origin->market_trip_id, 422, 'The purchase must belong to the planned market trip.');
                    $used = $this->sum(OrderSupply::query()->where('origin_supply_id', $origin->id)->pluck('quantity'));
                    if ($used->plus($qty)->isGreaterThan($this->decimal($origin->quantity))) {
                        $this->fail('Market purchases exceed this trip target.');
                    }
                }
            } else {
                $trip = MarketTrip::query()->where('business_id', $line->business_id)->where('status', '!=', 'closed')->findOrFail($data['market_trip_id'] ?? 0);
            }
            if (! $origin && $qty->isGreaterThan($this->decimal($this->summary($line)['shortage']))) {
                $this->fail('This commitment exceeds uncovered demand.');
            }
            $s = OrderSupply::create(['business_id' => $line->business_id, 'sales_order_line_id' => $line->id, 'kind' => $data['kind'], 'quantity' => (string) $qty, 'purchase_order_id' => $purchase?->id, 'market_trip_id' => $trip?->id ?? $purchase?->market_trip_id, 'origin_supply_id' => $origin?->id, 'reason' => $data['reason'], 'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_by' => $actor]);
            app(OrderOperations::class)->event($line->order, $actor, 'supply.committed', $data['reason'], ['supply_id' => $s->id, 'quantity' => (string) $qty]);

            return $s;
        }, 3);
    }

    public function receive(GoodsReceipt $receipt, int $actor): void
    {
        $gross = $this->decimal($receipt->received_quantity);
        $accepted = $this->decimal($receipt->accepted_quantity);
        $supplies = OrderSupply::query()->where('business_id', $receipt->business_id)->where('purchase_order_id', $receipt->purchase_order_id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
        foreach ($supplies as $s) {
            $prior = $this->sum(OrderReceiptAllocation::query()->where('order_supply_id', $s->id)->pluck('received_quantity'));
            $remaining = $this->decimal($s->quantity)->minus($prior);
            $take = BigDecimal::min($remaining, $gross);
            if (! $take->isGreaterThan(0)) {
                continue;
            }
            $good = BigDecimal::min($take, $accepted);
            $bad = $take->minus($good);
            OrderReceiptAllocation::create(['business_id' => $receipt->business_id, 'order_supply_id' => $s->id, 'goods_receipt_id' => $receipt->id, 'received_quantity' => (string) $take, 'accepted_quantity' => (string) $good, 'rejected_quantity' => (string) $bad]);
            if ($good->isGreaterThan(0)) {
                $line = $this->line($receipt->business_id, $s->sales_order_line_id);
                $this->reserve($line, $receipt->batch, ['quantity' => (string) $good, 'reason' => 'Accepted receipt '.$receipt->reference.' allocated FIFO to committed demand.', 'request_key' => (string) Str::uuid()], $actor, $s->id);
            }
            $gross = $gross->minus($take);
            $accepted = $accepted->minus($good);
        }
    }

    public function consume(PackingJob $job, StockBatch $batch, BigDecimal $qty, bool $loss): void
    {
        if ($job->sales_order_line_id) {
            $this->line($job->business_id, $job->sales_order_line_id);
        }
        $available = $this->free($batch, $job->sales_order_line_id);
        if ($qty->isGreaterThan($available)) {
            $this->fail('Stock is reserved for another order. Choose this order’s reservation or free stock.', 'contents');
        }
        if (! $job->sales_order_line_id) {
            return;
        }
        $line = $this->line($job->business_id, $job->sales_order_line_id);
        $own = StockReservation::query()->where('business_id', $job->business_id)->where('sales_order_line_id', $line->id)->where('batch_id', $batch->id)->whereNull('released_at')->orderBy('id')->lockForUpdate()->get();
        $balance = $this->sum($own->map(fn ($r) => (string) $this->decimal($r->quantity)->minus($r->consumed_quantity)->minus($r->lost_quantity)));
        if ($qty->isGreaterThan($balance)) {
            $this->fail('Reserve this batch for the order before packing or recording its sorting loss.', 'contents');
        }
        if (! $loss && $qty->plus($this->decimal($this->summary($line)['packed']))->isGreaterThan($this->decimal($line->quantity))) {
            $this->fail('Packed output exceeds the order quantity.', 'contents');
        }
        foreach ($own as $r) {
            $take = BigDecimal::min($qty, $this->decimal($r->quantity)->minus($r->consumed_quantity)->minus($r->lost_quantity));
            $field = $loss ? 'lost_quantity' : 'consumed_quantity';
            $r->update([$field => (string) $this->decimal($r->$field)->plus($take)]);
            $qty = $qty->minus($take);
            if (! $qty->isGreaterThan(0)) {
                break;
            }
        }
    }
}
