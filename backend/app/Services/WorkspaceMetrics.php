<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\ExportShipment;
use App\Models\FinancePostingRequest;
use App\Models\SalesOrder;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;

class WorkspaceMetrics
{
    public function overview(BusinessMembership $member): array
    {
        $businessId = $member->business_id;
        $access = app(BusinessPermissions::class);
        $cards = [];

        if ($access->allows($member, 'sales.orders.view')) {
            $cards[] = [
                'label' => 'Confirmed customer orders',
                'value' => SalesOrder::query()->where('business_id', $businessId)
                    ->where('status', 'confirmed')->count(),
                'detail' => 'Confirmed orders on record',
                'route' => 'sales-orders.index',
            ];
        }
        if ($access->allows($member, 'stock.view')) {
            // The unit of each batch may differ (kg, pieces, cartons). Count
            // positive-balance batches rather than summing incompatible units.
            $totals = [];
            StockMovement::query()->where('business_id', $businessId)
                ->select(['batch_id', 'quantity'])->orderBy('id')
                ->chunk(500, function ($movements) use (&$totals) {
                    foreach ($movements as $movement) {
                        $key = (int) $movement->batch_id;
                        $totals[$key] = ($totals[$key] ?? BigDecimal::zero())
                            ->plus((string) $movement->quantity);
                    }
                });
            $heldBatchIds = StockBatch::query()
                ->where('business_id', $businessId)->where('is_quarantined', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->flip();
            $readyBatches = count(array_filter(
                $totals,
                fn (BigDecimal $quantity, int $batchId) => $quantity->isGreaterThan(0) && ! $heldBatchIds->has($batchId),
                ARRAY_FILTER_USE_BOTH,
            ));
            $cards[] = [
                'label' => 'Batches ready for packing',
                'value' => $readyBatches,
                'detail' => 'Positive, quality-cleared unpacked stock',
                'route' => 'stock.index',
            ];
        }
        if ($access->allows($member, 'shipments.view')) {
            $cards[] = [
                'label' => 'Shipments preparing',
                'value' => ExportShipment::query()->where('business_id', $businessId)
                    ->whereIn('status', ['draft', 'ready'])->count(),
                'detail' => 'Draft or ready for dispatch',
                'route' => 'shipments.index',
            ];
        }
        if ($access->allows($member, 'finance.reviews.view')) {
            $cards[] = [
                'label' => 'Finance reviews pending',
                'value' => FinancePostingRequest::query()->where('business_id', $businessId)
                    ->where('status', 'pending')->count(),
                'detail' => 'Awaiting independent review',
                'route' => 'finance.reviews',
            ];
        }

        return $cards;
    }

    public function charts(BusinessMembership $member): array
    {
        $access = app(BusinessPermissions::class);
        $orders = null;
        $shipments = null;

        if ($access->allows($member, 'sales.orders.view')) {
            $start = today('Africa/Kampala')->subDays(13);
            $end = $start->copy()->addDays(14);
            $query = SalesOrder::query()->where('business_id', $member->business_id)
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->where('confirmed_at', '>=', $start->copy()->utc())
                ->where('confirmed_at', '<', $end->copy()->utc());
            $labels = [];

            for ($day = 0; $day < 14; $day++) {
                $from = $start->copy()->addDays($day);
                $labels[] = $from->format('d M');
                $query->selectRaw(
                    "COALESCE(SUM(CASE WHEN confirmed_at >= ? AND confirmed_at < ? THEN 1 ELSE 0 END), 0) AS day_{$day}",
                    [$from->copy()->utc(), $from->copy()->addDay()->utc()],
                );
            }

            $counts = $query->first();
            $values = [];
            for ($day = 0; $day < 14; $day++) {
                $values[] = (int) $counts->getAttribute("day_{$day}");
            }
            $orders = ['labels' => $labels, 'values' => $values, 'total' => array_sum($values)];
        }

        if ($access->allows($member, 'shipments.view')) {
            $counts = ExportShipment::query()->where('business_id', $member->business_id)
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $shipments = [];
            foreach (['draft' => 'Draft', 'ready' => 'Ready', 'dispatched' => 'In transit', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $status => $label) {
                $shipments[] = ['label' => $label, 'value' => (int) ($counts[$status] ?? 0)];
            }
        }

        return compact('orders', 'shipments');
    }
}
