<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Carton;
use App\Models\OrderEvent;
use App\Models\OrderSupply;
use App\Models\OrderTask;
use App\Models\PackingJob;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesRequest;
use App\Models\StaffPerson;
use App\Models\StockReservation;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderOperations
{
    public function lockForWork(int $businessId, string $module): Business
    {
        $business = Business::query()->whereKey($businessId)->where('is_active', true)->lockForUpdate()->firstOrFail();
        app(PlatformTenancy::class)->assertModuleWrite($business, $module);

        return $business;
    }

    public function event(SalesOrder $order, ?int $actor, string $action, string $reason, array $details = [], ?string $customerNote = null): void
    {
        OrderEvent::create(['business_id' => $order->business_id, 'sales_order_id' => $order->id, 'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'details' => $details, 'customer_note' => $customerNote]);
    }

    public function approve(Business $business, int $requestId, int $actor, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($business, $requestId, $actor, $reason) {
            $this->lockForWork($business->id, 'sales');
            $request = SalesRequest::query()->where('business_id', $business->id)->lockForUpdate()->with('items.product', 'customer')->findOrFail($requestId);
            if ($existing = $request->order()->first()) {
                return $existing;
            }
            abort_unless(in_array($request->status, ['open', 'quoted'], true) && $request->customer->active, 422, 'Only an open request for an active customer can be approved.');
            abort_unless($request->items->isNotEmpty(), 422, 'The request needs at least one product.');
            $order = SalesOrder::create(['business_id' => $business->id, 'reference' => 'SO-'.Str::upper((string) Str::ulid()), 'sales_request_id' => $request->id, 'sales_quotation_id' => null, 'customer_id' => $request->customer_id, 'status' => 'confirmed', 'pricing_status' => 'unpriced', 'currency' => $request->customer->currency, 'required_on' => $request->required_on, 'destination' => $request->destination, 'subtotal' => null, 'discount_amount' => null, 'tax_amount' => null, 'grand_total' => null, 'buyer_confirmation' => 'Operational approval only; commercial pricing pending.', 'notes' => $request->notes, 'confirmed_by' => $actor, 'confirmed_at' => now()]);
            foreach ($request->items as $item) {
                abort_unless($item->product->active, 422, 'An ordered product is inactive. Review the request before approval.');
                SalesOrderLine::create(['business_id' => $business->id, 'sales_order_id' => $order->id, 'product_id' => $item->product_id, 'quantity' => $item->quantity, 'unit_price' => null, 'line_total' => null, 'product_name' => $item->product->name, 'product_sku' => $item->product->sku, 'unit' => $item->product->base_unit]);
            }
            $request->update(['status' => 'approved']);
            $this->event($order, $actor, 'order.approved', $reason);

            return $order;
        }, 3);
    }

    public function person(int $businessId, int $id): StaffPerson
    {
        $person = StaffPerson::query()->where('business_id', $businessId)->where('active', true)->findOrFail($id);
        if ($person->user_id) {
            abort_unless(BusinessMembership::query()->where('business_id', $businessId)->where('user_id', $person->user_id)->where('active', true)->exists(), 422, 'This staff member no longer has active business access.');
        }

        return $person;
    }

    public function task(SalesOrder $order, array $data, int $actor): OrderTask
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $this->lockForWork($order->business_id, 'sales');
            $order->refresh();
            abort_unless($order->status === 'confirmed', 422, 'This order is closed.');
            $this->person($order->business_id, (int) $data['staff_person_id']);
            $existing = OrderTask::query()->where('business_id', $order->business_id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->sales_order_id === $order->id && $existing->title === $data['title'] && $existing->staff_person_id === (int) $data['staff_person_id'], 409, 'This submission key was already used for different work.');

                return $existing;
            }
            $task = OrderTask::create([...$data, 'due_at' => Carbon::parse($data['due_at'], $order->salesRequest->business?->timezone ?? 'Africa/Kampala')->utc(), 'business_id' => $order->business_id, 'sales_order_id' => $order->id, 'created_by' => $actor]);
            $this->event($order, $actor, 'task.assigned', $data['note'] ?? 'Work assigned.', ['task_id' => $task->id, 'staff_person_id' => $task->staff_person_id]);

            return $task;
        }, 3);
    }

    public function progress(SalesOrder $order): array
    {
        $summary = app(SalesFulfillment::class)->summary($order);
        $ordered = BigDecimal::zero();
        $shipped = BigDecimal::zero();
        $delivered = BigDecimal::zero();
        $booked = BigDecimal::zero();
        foreach ($summary['lines'] as $line) {
            $ordered = $ordered->plus($line['ordered']);
            $shipped = $shipped->plus($line['dispatched']);
            $delivered = $delivered->plus($line['delivered']);
            $booked = $booked->plus($line['allocated']);
        }
        $stage = match (true) {
            $order->status !== 'confirmed' => $order->status,$delivered->isEqualTo($ordered) && $ordered->isGreaterThan(0) => 'delivered',$shipped->isEqualTo($ordered) && $ordered->isGreaterThan(0) => 'dispatched',$shipped->isGreaterThan(0) => 'partly_shipped',$booked->isEqualTo($ordered) && $ordered->isGreaterThan(0) => 'ready',PackingJob::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $order->lines->modelKeys())->exists() => 'preparing',OrderSupply::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $order->lines->modelKeys())->where('status', 'active')->exists() => 'sourcing',default => 'reviewed'
        };

        return [...$summary, 'stage' => $stage];
    }

    public function close(SalesOrder $order, string $action, string $reason, int $actor): void
    {
        DB::transaction(function () use ($order, $action, $reason, $actor) {
            $this->lockForWork($order->business_id, 'sales');
            $order->refresh();
            abort_unless($order->status === 'confirmed', 422, 'This order is already closed.');
            $order->load('lines');
            $lineIds = $order->lines->modelKeys();
            abort_if($order->shipments()->whereIn('status', ['draft', 'ready'])->exists(), 422, 'Resolve draft or ready shipments first.');
            abort_if(PackingJob::query()->whereIn('sales_order_line_id', $lineIds)->whereIn('status', ['open', 'in_progress'])->exists(), 422, 'Complete existing packing work before closing.');
            $packed = Carton::query()->where('business_id', $order->business_id)->whereHas('packingJob', fn ($q) => $q->whereIn('sales_order_line_id', $lineIds))->whereNotIn('status', ['shipped', 'delivered'])->exists();
            abort_if($packed, 422, 'Packed cartons still need a controlled fulfilment or correction before closure.');
            $progress = $this->progress($order);
            foreach ($progress['lines'] as $index => $line) {
                if ($action === 'cancel') {
                    abort_if(BigDecimal::of($line['dispatched'])->isGreaterThan(0), 422, 'Use remainder closure after any dispatch.');
                }
                if ($action === 'complete') {
                    abort_unless(BigDecimal::of($line['delivered'])->isEqualTo(BigDecimal::of($line['ordered'])), 422, 'All ordered quantities must be delivered before completion.');
                }
                $order->lines[$index]->update(['closed_quantity' => (string) BigDecimal::of($line['ordered'])->minus($line['dispatched'])]);
            }
            StockReservation::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $lineIds)->whereNull('released_at')->update(['released_at' => now()]);
            OrderSupply::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $lineIds)->where('status', 'active')->update(['status' => 'closed']);
            OrderTask::query()->where('business_id', $order->business_id)->where('sales_order_id', $order->id)->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'cancelled', 'note' => $reason]);
            $order->update(['status' => $action === 'cancel' ? 'cancelled' : 'closed', 'closed_at' => now(), 'close_reason' => $reason]);
            $this->event($order, $actor, 'order.'.$action, $reason);
        }, 3);
    }
}
