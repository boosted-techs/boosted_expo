<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\MarketTrip;
use App\Models\OrderEvent;
use App\Models\OrderSupply;
use App\Models\OrderTask;
use App\Models\PackingJob;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StaffPerson;
use App\Models\StockBatch;
use App\Models\StockReservation;
use App\Models\TransportHandover;
use App\Models\TransportLeg;
use App\Models\TransportPartner;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\PlatformAccess;
use App\Services\ProcurementMath;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderWorkspaceController extends Controller
{
    private function access(Request $request, string $permission): Business
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $permission), 403);

        return $request->attributes->get('activeBusiness');
    }

    private function own(Request $request, int $id, string $permission = 'sales.orders.view'): SalesOrder
    {
        $b = $this->access($request, $permission);

        return SalesOrder::query()->where('business_id', $b->id)->findOrFail($id);
    }

    public function approve(Request $request, int $record, OrderOperations $orders): RedirectResponse
    {
        $b = $this->access($request, 'sales.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $order = $orders->approve($b, $record, $request->user()->id, $data['reason']);

        return redirect()->route('sales-orders.show', $order)->with('status', 'Operational order approved. Pricing remains pending; supply planning can start.');
    }

    public function show(Request $request, int $order): View
    {
        $order = $this->own($request, $order)->load('customer', 'salesRequest', 'lines.product', 'invoices');
        $member = $request->attributes->get('membership');
        $permission = app(BusinessPermissions::class);
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'supply', 'preparation', 'delivery', 'money', 'history'])]])['tab'] ?? 'overview';
        $canMoney = $permission->allows($member, 'sales.view') || $permission->allows($member, 'finance.view');
        abort_if($tab === 'money' && ! $canMoney, 403);
        $canSource = $permission->allows($member, 'purchases.create');
        $canManage = $permission->allows($member, 'sales.manage');
        $canPack = $permission->allows($member, 'packing.manage');
        $canShip = $permission->allows($member, 'shipments.manage');
        $summary = app(OrderOperations::class)->progress($order);
        $supply = [];
        foreach ($order->lines as $line) {
            $supply[$line->id] = app(OrderSourcing::class)->summary($line);
        }
        $people = StaffPerson::query()->where('business_id', $order->business_id)->where('active', true)->orderBy('name')->get();
        $tasks = OrderTask::query()->where('business_id', $order->business_id)->where('sales_order_id', $order->id)->with('person')->orderBy('due_at')->get();
        $events = OrderEvent::query()->where('business_id', $order->business_id)->where('sales_order_id', $order->id)->latest('id')->paginate(25);
        $trips = MarketTrip::query()->where('business_id', $order->business_id)->whereIn('id', OrderSupply::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $order->lines->modelKeys())->select('market_trip_id'))->with('person')->get();
        $allTrips = $canSource ? MarketTrip::query()->where('business_id', $order->business_id)->where('status', '!=', 'closed')->with('person')->orderBy('due_at')->get() : collect();
        $shipments = $summary['shipments'];
        $legs = TransportLeg::query()->where('business_id', $order->business_id)->whereIn('shipment_id', $shipments->modelKeys())->with('person', 'shipment')->orderBy('shipment_id')->orderBy('sequence')->get();
        $handovers = TransportHandover::query()->where('business_id', $order->business_id)->whereIn('transport_leg_id', $legs->modelKeys())->orderBy('occurred_at')->get()->groupBy('transport_leg_id');
        $partners = TransportPartner::query()->where('business_id', $order->business_id)->where('active', true)->orderBy('name')->get();
        $reservations = StockReservation::query()->where('business_id', $order->business_id)->whereIn('sales_order_line_id', $order->lines->modelKeys())->with('batch')->get();

        return view('sales.orders.workspace', compact('order', 'tab', 'summary', 'supply', 'tasks', 'events', 'people', 'trips', 'allTrips', 'legs', 'handovers', 'partners', 'shipments', 'reservations', 'canMoney', 'canSource', 'canManage', 'canPack', 'canShip'));
    }

    public function candidates(Request $request, int $order, int $line, OrderSourcing $source): JsonResponse
    {
        $o = $this->own($request, $order, 'purchases.create');
        $l = $source->line($o->business_id, $line);
        abort_unless($l->sales_order_id === $o->id, 404);
        $data = $request->validate(['kind' => ['required', Rule::in(['batch', 'purchase'])], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = $data['search'] ?? '';
        if ($data['kind'] === 'batch') {
            $rows = StockBatch::query()->where('business_id', $o->business_id)->where('product_id', $l->product_id)->where('is_quarantined', false)->when($search !== '', fn ($q) => $q->where('batch_code', 'like', '%'.$search.'%'))->orderBy('id')->paginate(20);
            $items = $rows->map(fn ($b) => ['id' => $b->id, 'label' => $b->batch_code.' · Free '.$source->free($b).' '.$l->unit]);
        } else {
            $rows = PurchaseOrder::query()->where('business_id', $o->business_id)->where('product_id', $l->product_id)->whereIn('status', ['ordered', 'part_received'])->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('vendor_name', 'like', '%'.$search.'%')->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))))->with('supplier')->orderBy('id')->paginate(20);
            $items = $rows->map(fn ($p) => ['id' => $p->id, 'label' => $p->reference.' · '.($p->supplier?->name ?? $p->vendor_name).' · '.$p->quantity.' '.$l->unit]);
        }

        return response()->json(['items' => $items->values(), 'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null]);
    }

    public function reserve(Request $request, int $order, int $line, OrderSourcing $source): RedirectResponse
    {
        $o = $this->own($request, $order, 'purchases.create');
        $data = $request->validate(['batch_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'reason' => ['required', 'string', 'min:10', 'max:1000'], 'request_key' => ['required', 'uuid']]);
        $l = $source->line($o->business_id, $line);
        abort_unless($l->sales_order_id === $o->id, 404);
        $batch = StockBatch::query()->where('business_id', $o->business_id)->findOrFail($data['batch_id']);
        $source->reserve($l, $batch, $data, $request->user()->id);

        return back()->with('status', 'Cleared stock reserved for this order.');
    }

    public function supply(Request $request, int $order, int $line, OrderSourcing $source): RedirectResponse
    {
        $o = $this->own($request, $order, 'purchases.create');
        $data = $request->validate(['kind' => ['required', Rule::in(['purchase', 'market'])], 'purchase_order_id' => ['nullable', 'integer'], 'market_trip_id' => ['nullable', 'integer'], 'origin_supply_id' => ['nullable', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'reason' => ['required', 'string', 'min:10', 'max:1000'], 'request_key' => ['required', 'uuid']]);
        $l = $source->line($o->business_id, $line);
        abort_unless($l->sales_order_id === $o->id, 404);
        $source->supply($l, $data, $request->user()->id);

        return back()->with('status', 'Supply commitment connected. Receiving will allocate accepted produce FIFO to committed demand.');
    }

    public function release(Request $request, int $order): RedirectResponse
    {
        $o = $this->own($request, $order, 'purchases.create');
        if ($request->filled('selection')) {
            $choice = $request->validate(['selection' => ['required', 'regex:/^(reservation|supply):[1-9][0-9]*$/D']]);
            [$kind,$id] = explode(':', $choice['selection']);
            $request->merge(['kind' => $kind, 'record_id' => (int) $id]);
        }
        $data = $request->validate(['kind' => ['required', Rule::in(['reservation', 'supply'])], 'record_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($o, $data, $request) {
            Business::query()->whereKey($o->business_id)->lockForUpdate()->firstOrFail();
            $lineIds = $o->lines()->pluck('id');
            if ($data['kind'] === 'reservation') {
                $record = StockReservation::query()->where('business_id', $o->business_id)->whereIn('sales_order_line_id', $lineIds)->findOrFail($data['record_id']);
                abort_if($record->released_at, 422, 'Reservation already released.');
                $record->update(['released_at' => now()]);
            } else {
                $record = OrderSupply::query()->where('business_id', $o->business_id)->whereIn('sales_order_line_id', $lineIds)->where('status', 'active')->findOrFail($data['record_id']);
                abort_if($record->origin_supply_id, 422, 'Resolve the originating trip target as well before releasing purchased market supply.');
                $record->update(['status' => 'released']);
            }
            app(OrderOperations::class)->event($o, $request->user()->id, 'supply.released', $data['reason'], ['kind' => $data['kind'], 'record_id' => $record->id]);
        }, 3);

        return back()->with('status', 'Unused commitment / reservation released; historical receipts and packed quantities remain.');
    }

    public function trip(Request $request, int $order): RedirectResponse
    {
        $o = $this->own($request, $order, 'purchases.create');
        $business = $request->attributes->get('activeBusiness');
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'location' => ['required', 'string', 'max:150'], 'due_at' => ['required', 'date_format:Y-m-d\TH:i'], 'budget' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'notes' => ['required', 'string', 'min:10', 'max:2000'], 'request_key' => ['required', 'uuid']]);
        DB::transaction(function () use ($o, $data, $request) {
            Business::query()->whereKey($o->business_id)->lockForUpdate()->firstOrFail();
            abort_unless($o->fresh()->status === 'confirmed', 422);
            app(OrderOperations::class)->person($o->business_id, (int) $data['staff_person_id']);
            $old = MarketTrip::query()->where('business_id', $o->business_id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_unless($old->location === $data['location'] && $old->staff_person_id === (int) $data['staff_person_id'], 409);

                return;
            }$trip = MarketTrip::create([...$data, 'due_at' => Carbon::parse($data['due_at'], 'Africa/Kampala')->utc(), 'business_id' => $o->business_id, 'reference' => 'MKT-'.Str::upper((string) Str::ulid()), 'created_by' => $request->user()->id]);
            app(OrderOperations::class)->event($o, $request->user()->id, 'market_trip.planned', $data['notes'], ['trip_id' => $trip->id]);
        });

        return back()->with('status', 'Market trip planned. Connect its target quantities below. Budget is a planning amount; cash advances are Phase 3.');
    }

    public function tripProgress(Request $request, int $trip): RedirectResponse
    {
        $b = $request->attributes->get('activeBusiness');
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'closed'])], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($b, $trip, $data, $request) {
            app(OrderOperations::class)->lockForWork($b->id, 'procurement');
            $record = MarketTrip::query()->where('business_id', $b->id)->with('person')->findOrFail($trip);
            $manager = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'purchases.create');
            abort_unless($manager || $record->person->user_id === $request->user()->id, 403);
            abort_unless($record->status !== 'closed', 422, 'This trip is already closed.');
            if ($data['status'] === 'closed') {
                foreach (OrderSupply::query()->where('business_id', $b->id)->where('market_trip_id', $record->id)->where('kind', 'market')->where('status', 'active')->get() as $target) {
                    $bought = app(ProcurementMath::class)->sum(OrderSupply::query()->where('origin_supply_id', $target->id)->pluck('quantity')->all());
                    abort_unless($bought->isEqualTo((string) $target->quantity), 422, 'Buy the target quantity or release unused market targets before closing this trip.');
                }
            }
            $record->update(['status' => $data['status']]);
            app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'market_trip.'.$data['status'], $data['reason'], [], ['trip_id' => $record->id]);
        }, 3);

        return back()->with('status', 'Trip progress recorded. Trip closure does not settle advances or approve expenses.');
    }

    public function packing(Request $request, int $order, int $line, OrderSourcing $source): RedirectResponse
    {
        $o = $this->own($request, $order, 'packing.manage');
        $l = $source->line($o->business_id, $line);
        abort_unless($l->sales_order_id === $o->id, 404);
        $data = $request->validate(['planned_on' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']]);
        $job = DB::transaction(function () use ($o, $l, $data, $request) {
            Business::query()->whereKey($o->business_id)->lockForUpdate()->firstOrFail();
            abort_unless($o->fresh()->status === 'confirmed', 422);
            $old = PackingJob::query()->where('business_id', $o->business_id)->where('sales_order_line_id', $l->id)->whereIn('status', ['open', 'in_progress'])->first();
            if ($old) {
                return $old;
            }$job = PackingJob::create(['business_id' => $o->business_id, 'sales_order_line_id' => $l->id, 'product_id' => $l->product_id, 'reference' => 'PK-'.Str::upper((string) Str::ulid()), 'status' => 'open', 'created_by' => $request->user()->id, 'planned_on' => $data['planned_on'] ?? $o->required_on, 'notes' => $data['notes'] ?? null]);
            app(OrderOperations::class)->event($o, $request->user()->id, 'packing.planned', 'Order-linked packing job created.', ['packing_job_id' => $job->id]);

            return $job;
        });

        return redirect()->route('packing.show', $job);
    }

    public function task(Request $request, int $order, OrderOperations $operations): RedirectResponse
    {
        $o = $this->own($request, $order, 'sales.manage');
        $data = $request->validate(['staff_person_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:150'], 'kind' => ['required', Rule::in(['sourcing', 'receiving', 'packing', 'delivery', 'follow_up'])], 'due_at' => ['required', 'date_format:Y-m-d\TH:i'], 'note' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']]);
        $operations->task($o, $data, $request->user()->id);

        return back()->with('status', 'Work assigned to the named staff member.');
    }

    public function taskProgress(Request $request, int $task): RedirectResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'staff.clock'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'blocked', 'completed', 'cancelled'])], 'note' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($b, $task, $data, $request) {
            Business::query()->whereKey($b->id)->lockForUpdate()->firstOrFail();
            $record = OrderTask::query()->where('business_id', $b->id)->with('person', 'order')->findOrFail($task);
            $manager = app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage');
            abort_unless($manager || $record->person->user_id === $request->user()->id, 403);
            abort_unless($record->order->status === 'confirmed' && ! in_array($record->status, ['completed', 'cancelled'], true), 422, 'Closed work cannot be overwritten.');
            if ($data['status'] === 'cancelled') {
                abort_unless($manager, 403);
            }$record->update($data);
            app(OrderOperations::class)->event($record->order, $request->user()->id, 'task.'.$data['status'], $data['note'], ['task_id' => $record->id]);
        });

        return back()->with('status', 'Work progress recorded. Completion is a staff report; stock and shipment quantities remain source-derived.');
    }

    public function close(Request $request, int $order, OrderOperations $operations): RedirectResponse
    {
        $o = $this->own($request, $order, 'sales.manage');
        $data = $request->validate(['action' => ['required', Rule::in(['cancel', 'close_remainder', 'complete'])], 'reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $operations->close($o, $data['action'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Order closure recorded without deleting fulfilment or financial history.');
    }

    public function note(Request $request, int $order, OrderOperations $operations): RedirectResponse
    {
        $o = $this->own($request, $order, 'sales.manage');
        $data = $request->validate(['customer_note' => ['required', 'string', 'min:5', 'max:1500'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $operations->event($o, $request->user()->id, 'customer.update', $data['reason'], [], $data['customer_note']);

        return back()->with('status', 'Customer-facing update published. Internal work notes remain private.');
    }
}
