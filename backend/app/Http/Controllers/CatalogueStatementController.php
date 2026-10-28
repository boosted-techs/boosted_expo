<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PartnerFollowup;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\CatalogueStatements;
use App\Services\OrderOperations;
use App\Services\PlatformAccess;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogueStatementController extends Controller
{
    public function show(Request $request, string $kind, int $record, CatalogueStatements $statements): View
    {
        $business = $request->attributes->get('activeBusiness');
        $access = app(BusinessPermissions::class);
        $member = $request->attributes->get('membership');
        abort_unless($access->allows($member, $kind === 'customer' ? 'customers.view' : 'catalogue.view'), 403);
        $record = ($kind === 'customer' ? Customer::query() : Product::query()->with('codes.customer'))->where('business_id', $business->id)->findOrFail($record);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'kind' => ['nullable', Rule::in(['order', 'purchase', 'receiving', 'stock', 'quality', 'return', 'invoice', 'payment', 'credit', 'packing', 'shipment'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $activities = $statements->activity($request, $record, $filters);
        $metrics = $kind === 'customer' ? $statements->customerMetrics($request, $record, $filters) : $statements->productMetrics($request, $record, $filters);
        $canMoney = $access->allows($member, 'finance.view');
        $canSettings = $access->allows($member, 'business.settings');

        $activityTypes = [];
        foreach (['order' => 'sales.orders.view', 'shipment' => 'shipments.view', 'return' => 'stock.view'] as $type => $permission) {
            if ($access->allows($member, $permission)) {
                $activityTypes[] = $type;
            }
        }
        if ($kind === 'product') {
            foreach (['purchase' => 'purchases.view', 'receiving' => 'receipts.view', 'stock' => 'stock.view', 'quality' => 'stock.view', 'packing' => 'packing.view'] as $type => $permission) {
                if ($access->allows($member, $permission)) {
                    $activityTypes[] = $type;
                }
            }
        }
        if ($canMoney) {
            array_push($activityTypes, 'invoice');
            if ($kind === 'customer') {
                array_push($activityTypes, 'payment', 'credit');
            }
        }

        return view('statements.show', compact('business', 'kind', 'record', 'filters', 'activities', 'metrics', 'canMoney', 'canSettings', 'activityTypes'));
    }

    public function week(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'business.settings'), 403);
        $data = $request->validate(['statement_week_start' => ['required', 'integer', 'between:0,6']]);
        DB::transaction(function () use ($request, $data): void {
            $business = Business::query()->lockForUpdate()->findOrFail($request->attributes->get('activeBusiness')->id);
            $before = $business->statement_week_start;
            $business->update($data);
            app(PlatformAccess::class)->audit($business->id, $request->user()->id, 'statement.week_start', 'Statement week updated.', ['day' => $before], $data);
        });

        return $request->expectsJson() ? response()->json(['message' => 'Week start saved. Apply filters to regroup the statement.']) : back()->with('status', 'Statement week updated.');
    }

    public function archiveOrder(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'], 'restore' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($request, $b, $order, $data): void {
            app(OrderOperations::class)->lockForWork($b->id, 'sales');
            $record = SalesOrder::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($order);
            abort_unless($record->status === 'cancelled' && ! $record->shipments()->exists() && ! $record->invoices()->exists(), 422, 'Only cancelled orders without shipments or invoices can be archived.');
            $record->forceFill(['archived_at' => $request->boolean('restore') ? null : now()])->save();
            app(OrderOperations::class)->event($record, $request->user()->id, $request->boolean('restore') ? 'order.restored' : 'order.archived', $data['reason']);
        });

        return $request->expectsJson() ? response()->json(['message' => 'Order archive updated. Apply filters to refresh.']) : back()->with('status', 'Order archive updated.');
    }

    public function archiveFollowup(Request $request, string $kind, int $partner, int $followup): RedirectResponse|JsonResponse
    {
        $b = $request->attributes->get('activeBusiness');
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), $kind === 'supplier' ? 'purchases.create' : 'sales.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'], 'restore' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($b, $kind, $partner, $followup, $data, $request): void {
            $record = PartnerFollowup::query()->where('business_id', $b->id)->where($kind.'_id', $partner)->withTrashed()->lockForUpdate()->findOrFail($followup);
            if ($request->boolean('restore')) {
                $record->restore();
            } else {
                $record->delete();
            }app(PlatformAccess::class)->audit($b->id, $request->user()->id, 'partner.followup.archive', $data['reason'], [], ['id' => $record->id, 'restored' => $request->boolean('restore')]);
        });

        return $request->expectsJson() ? response()->json(['message' => 'Follow-up updated. Apply filters to refresh.']) : back()->with('status', 'Follow-up updated.');
    }
}
