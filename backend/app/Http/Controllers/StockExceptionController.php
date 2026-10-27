<?php

namespace App\Http\Controllers;

use App\Models\Carton;
use App\Models\CartonRepack;
use App\Models\FinancePostingRequest;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockLocationEvent;
use App\Services\OrderOperations;
use App\Services\OrderSourcing;
use App\Services\PlatformAccess;
use App\Services\StockAvailability;
use App\Services\StockExceptions;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockExceptionController extends Controller
{
    private function check(Request $r, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r, 'stock.view');
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $r->input('search'));
        $batches = StockBatch::query()->where('business_id', $business->id)->with('product')->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('batch_code', 'like', '%'.$search.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$search.'%'))))->latest('id')->paginate(25, ['*'], 'batches_page')->withQueryString();
        $available = $batches->mapWithKeys(fn ($b) => [$b->id => app(StockAvailability::class)->available($business->id, $b->id)]);
        $reserved = $batches->mapWithKeys(fn ($b) => [$b->id => app(OrderSourcing::class)->reserved($business->id, $b->id)]);
        $locations = StockLocation::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get()->keyBy('id');
        $counts = StockAdjustment::query()->where('business_id', $business->id)->with('batch.product')->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhereHas('batch', fn ($b) => $b->where('batch_code', 'like', '%'.$search.'%'))))->latest('id')->paginate(20, ['*'], 'counts_page')->withQueryString();
        $cartons = Carton::query()->where('business_id', $business->id)->where('status', 'packed')->whereDoesntHave('shipmentAllocation')->when($search !== '', fn ($q) => $q->where('carton_code', 'like', '%'.$search.'%'))->latest('id')->paginate(20, ['*'], 'cartons_page')->withQueryString();
        $repacks = CartonRepack::query()->where('business_id', $business->id)->with('carton')->latest('id')->paginate(20, ['*'], 'repacks_page')->withQueryString();
        $moves = StockLocationEvent::query()->where('business_id', $business->id)->latest('id')->paginate(20, ['*'], 'moves_page')->withQueryString();
        $access = app(BusinessPermissions::class);
        $member = $r->attributes->get('membership');
        $canRecord = $access->allows($member, 'stock.adjust.record');
        $canReview = $access->allows($member, 'stock.adjust.review');
        $canRepack = $access->allows($member, 'packing.manage');
        $canFinance = $access->allows($member, 'finance.view');
        $canSubmit = $access->allows($member, 'finance.reviews.request');

        return view('stock.exceptions', compact('business', 'batches', 'available', 'reserved', 'locations', 'counts', 'cartons', 'repacks', 'moves', 'canRecord', 'canReview', 'canRepack', 'canFinance', 'canSubmit'));
    }

    public function count(Request $r, StockExceptions $exceptions): RedirectResponse
    {
        $this->check($r, 'stock.adjust.record');
        $data = $r->validate(['batch_id' => ['required', 'integer', 'min:1'], 'counted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $exceptions->count($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return back()->with('status', 'Count recorded against current loose stock and reservations. Independent review is required; stock has not changed yet.');
    }

    public function reviewCount(Request $r, int $count, string $decision, StockExceptions $exceptions): RedirectResponse
    {
        $this->check($r, 'stock.adjust.review');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $count = StockAdjustment::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($count);
        $exceptions->review($count, $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', $decision === 'approve' ? 'Count reviewed. A non-zero variance still needs finance posting before quantity and value change.' : 'Count rejected and retained in history.');
    }

    public function cancelCount(Request $r, int $count): RedirectResponse
    {
        $this->check($r, 'stock.adjust.record');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        DB::transaction(function () use ($r, $count, $data, $business) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            $count = StockAdjustment::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($count);
            abort_unless($count->posting_status === 'not_posted' && in_array($r->user()->id, [$count->created_by, $count->reviewed_by], true), 422, 'Only an involved recorder/reviewer can withdraw an unposted count.');
            abort_if(FinancePostingRequest::query()->where('business_id', $business->id)->where('source_type', 'stock_adjustment')->where('source_id', $count->id)->exists(), 422, 'Withdraw or reject the finance review first.');
            $count->update(['status' => 'cancelled', 'posting_status' => 'rejected']);
            app(PlatformAccess::class)->audit($business->id, $r->user()->id, 'stock.count_withdrawn', $data['reason'], [], ['count_id' => $count->id]);
        }, 3);

        return back()->with('status', 'Unposted count withdrawn. The original observation and review remain in history.');
    }

    public function location(Request $r): RedirectResponse
    {
        $this->check($r, 'stock.adjust.record');
        $data = $r->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $business = $r->attributes->get('activeBusiness');
        DB::transaction(function () use ($business, $data) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            StockLocation::query()->firstOrCreate(['business_id' => $business->id, 'name' => $data['name']], ['active' => true]);
        }, 3);

        return back()->with('status', 'Internal stock location available.');
    }

    public function move(Request $r, StockExceptions $exceptions): RedirectResponse
    {
        $this->check($r, 'stock.adjust.record');
        $data = $r->validate(['batch_id' => ['nullable', 'integer', 'min:1'], 'carton_id' => ['nullable', 'integer', 'min:1'], 'to_location_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $exceptions->move($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return back()->with('status', 'Location move recorded. Quantity and financial value are unchanged.');
    }

    public function repack(Request $r, StockExceptions $exceptions): RedirectResponse
    {
        $this->check($r, 'packing.manage');
        $data = $r->validate(['carton_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $exceptions->requestRepack($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return back()->with('status', 'Unpack/repack request recorded for independent review.');
    }

    public function reviewRepack(Request $r, int $repack, string $decision, StockExceptions $exceptions): RedirectResponse
    {
        $this->check($r, 'stock.adjust.review');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $repack = CartonRepack::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($repack);
        $exceptions->reviewRepack($repack, $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', $decision === 'approve' ? 'Carton unpacked to its original batches. Old carton history remains; its public QR is disabled. Pack replacements using the returned stock.' : 'Unpack/repack request rejected.');
    }
}
