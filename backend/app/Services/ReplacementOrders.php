<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ExportShipment;
use App\Models\SalesOrder;
use App\Models\SalesRequest;
use App\Models\SalesRequestItem;
use App\Models\StockBatch;
use App\Models\StockExceptionCase;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplacementOrders
{
    public function create(Business $business, StockExceptionCase $case, array $data, int $actor): SalesOrder
    {
        return DB::transaction(function () use ($business, $case, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'sales');
            $case = StockExceptionCase::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($case->id);
            $hash = app(FinanceWorkflows::class)->hash([$case->id, $data]);
            if ($request = SalesRequest::query()->where('business_id', $business->id)->where('submission_key', $data['request_key'])->first()) {
                abort_unless($request->submission_hash === $hash, 409, 'This submission already has different replacement details.');

                return $request->order()->firstOrFail();
            }
            abort_unless(in_array($case->kind, ['customer_return', 'quality_claim', 'recall'], true) && in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'Approve a replacement against an open customer return, claim or recall.');
            abort_unless($case->created_by !== $actor, 403, 'An independent owner or administrator must authorise a replacement.');
            abort_unless(hash_equals($case->snapshot_hash, app(FinanceWorkflows::class)->hash($case->data_snapshot)), 422, 'The case evidence changed.');
            $shipment = ExportShipment::query()->where('business_id', $business->id)->whereIn('status', ['dispatched', 'delivered'])->with('allocations.carton.contents')->findOrFail($data['shipment_id']);
            $batch = StockBatch::query()->where('business_id', $business->id)->findOrFail($case->batch_id);
            $affected = $case->kind === 'customer_return' ? [$case->batch_id] : app(StockExceptionCases::class)->lineage($business->id, $case->batch_id);
            $capacity = BigDecimal::zero();
            foreach ($shipment->allocations as $allocation) {
                foreach ($allocation->carton->contents as $content) {
                    if (in_array($content->batch_id, $affected, true) && $content->batch->product_id === $batch->product_id) {
                        $capacity = $capacity->plus((string) $content->quantity);
                    }
                }
            }
            if ($case->kind === 'customer_return') {
                abort_unless($case->shipment_id === $shipment->id, 422, 'Use the returned goods original shipment.');
                $capacity = BigDecimal::min($capacity, (string) $case->quantity);
            }
            $used = BigDecimal::zero();
            foreach (SalesOrder::query()->where('business_id', $business->id)->where('replacement_case_id', $case->id)->where('replacement_shipment_id', $shipment->id)->with('lines')->get() as $order) {
                foreach ($order->lines as $line) {
                    $used = $used->plus((string) $line->quantity)->minus((string) $line->closed_quantity);
                }
            }
            $quantity = BigDecimal::of($data['quantity']);
            abort_unless($quantity->isGreaterThan(0) && ! $used->plus($quantity)->isGreaterThan($capacity), 422, 'Replacement demand exceeds the affected original quantity.');
            $request = SalesRequest::create(['business_id' => $business->id, 'customer_id' => $shipment->customer_id, 'reference' => 'REP-'.Str::upper((string) Str::ulid()), 'source' => 'replacement', 'status' => 'open', 'required_on' => $data['required_on'], 'destination' => $shipment->destination_country, 'buyer_reference' => $data['external_reference'], 'notes' => $case->reference.' · '.$data['reason'], 'created_by' => $actor, 'submission_key' => $data['request_key'], 'submission_hash' => $hash]);
            SalesRequestItem::create(['business_id' => $business->id, 'sales_request_id' => $request->id, 'product_id' => $batch->product_id, 'quantity' => (string) $quantity]);
            $order = app(OrderOperations::class)->approve($business, $request->id, $actor, $data['reason']);
            $order->update(['order_type' => 'replacement', 'pricing_status' => 'replacement', 'replacement_case_id' => $case->id, 'replacement_shipment_id' => $shipment->id, 'buyer_confirmation' => 'Authorised replacement with no sales charge. '.$data['external_reference']]);
            app(OrderOperations::class)->event($order, $actor, 'replacement.authorised', $data['reason'], ['case_id' => $case->id, 'original_shipment_id' => $shipment->id]);
            app(StockExceptionCases::class)->event($case, 'replacement_authorised', $order->reference.' · '.$data['external_reference'].' · '.$data['reason'], $actor, $shipment->id);

            return $order;
        }, 3);
    }

    public function assertDelivered(StockExceptionCase $case): void
    {
        $orders = SalesOrder::query()->where('business_id', $case->business_id)->where('replacement_case_id', $case->id)->with('lines')->get();
        abort_unless($orders->isNotEmpty(), 422, 'Create and fulfil an authorised replacement order first.');
        foreach ($orders as $order) {
            $summary = app(OrderOperations::class)->progress($order);
            foreach ($summary['lines'] as $line) {
                abort_unless(BigDecimal::of($line['delivered'])->isEqualTo(BigDecimal::of($line['ordered'])), 422, 'Every replacement quantity must be delivered before recording a completed replacement outcome.');
            }
        }
    }
}
