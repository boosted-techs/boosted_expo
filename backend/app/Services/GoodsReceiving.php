<?php

namespace App\Services;

use App\Models\Business;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\QrIdentity;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoodsReceiving
{
    public function post(PurchaseOrder $purchase, array $data, int $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($purchase, $data, $userId) {
            Business::query()->whereKey($purchase->business_id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()
                ->where('business_id', $purchase->business_id)
                ->lockForUpdate()->findOrFail($purchase->id);

            $existing = GoodsReceipt::query()->where('business_id', $order->business_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->purchase_order_id === $order->id && BigDecimal::of((string) $existing->accepted_quantity)->isEqualTo(BigDecimal::of((string) $data['accepted_quantity'])) && BigDecimal::of((string) $existing->rejected_quantity)->isEqualTo(BigDecimal::of((string) $data['rejected_quantity'])) && $existing->received_on->toDateString() === $data['received_on'], 409, 'This receipt key was already used for a different receipt.');

                return $existing;
            }

            abort_unless(in_array($order->status, ['ordered', 'part_received'], true), 422,
                'This purchase is not open for receiving.');

            $math = app(ProcurementMath::class);
            $accepted = $math->decimal($data['accepted_quantity']);
            $rejected = $math->decimal($data['rejected_quantity']);
            $received = $accepted->plus($rejected);

            if ($received->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['accepted_quantity' => 'Record a positive received quantity.']);
            }

            $prior = $math->sum($order->receipts()->pluck('received_quantity')->all());
            $ordered = $math->decimal($order->quantity);
            if ($prior->plus($received)->isGreaterThan($ordered)) {
                throw ValidationException::withMessages([
                    'accepted_quantity' => 'The received quantity exceeds the unreceived quantity on this purchase.',
                ]);
            }

            $gross = $data['gross_weight_kg'] ?? null;
            $tare = $data['tare_weight_kg'] ?? null;
            if (($gross === null) !== ($tare === null)) {
                throw ValidationException::withMessages([
                    'gross_weight_kg' => 'Enter both gross weight and packaging (tare) weight, or leave both empty.',
                ]);
            }
            if ($gross !== null) {
                $net = $math->decimal($gross)->minus($math->decimal($tare));
                if ($net->isLessThan(0)) {
                    throw ValidationException::withMessages(['tare_weight_kg' => 'Tare weight cannot exceed gross weight.']);
                }
                if ($order->product->base_unit === 'kg' && ! $net->isEqualTo($received)) {
                    throw ValidationException::withMessages([
                        'gross_weight_kg' => 'For kilogram-based produce, net weight must equal accepted plus rejected kilograms.',
                    ]);
                }
            }

            $receipt = GoodsReceipt::create([
                'business_id' => $order->business_id,
                'reference' => 'GR-'.Str::upper((string) Str::ulid()),
                'purchase_order_id' => $order->id,
                'received_quantity' => (string) $received,
                'accepted_quantity' => (string) $accepted,
                'rejected_quantity' => (string) $rejected,
                'gross_weight_kg' => $gross,
                'tare_weight_kg' => $tare,
                'farmer_code' => $data['farmer_code'] ?? null,
                'quality_notes' => $data['quality_notes'] ?? null,
                'received_on' => $data['received_on'],
                'posted_by' => $userId,
                'idempotency_key' => $data['idempotency_key'],
            ]);

            if ($accepted->isGreaterThan(0)) {
                $batch = StockBatch::create([
                    'stock_location_id' => app(StockExceptions::class)->defaultLocation($order->business_id)->id,
                    'business_id' => $order->business_id,
                    'product_id' => $order->product_id,
                    'batch_code' => 'BT-'.Str::upper((string) Str::ulid()),
                    'source_reference' => $data['farmer_code'] ?? null,
                    'received_on' => $data['received_on'],
                ]);
                $receipt->update(['batch_id' => $batch->id]);
                QrIdentity::firstOrCreate(
                    ['business_id' => $order->business_id, 'level' => 'batch', 'record_id' => $batch->id],
                    ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
                );
                StockMovement::create([
                    'business_id' => $order->business_id, 'batch_id' => $batch->id,
                    'movement_type' => 'received', 'quantity' => (string) $accepted,
                    'unit' => $order->product->base_unit, 'source_type' => 'goods_receipt',
                    'source_id' => $receipt->id, 'created_by' => $userId,
                ]);
            }

            $order->update([
                'status' => $prior->plus($received)->isEqualTo($ordered) ? 'received' : 'part_received',
            ]);
            $receipt->refresh();
            app(OrderSourcing::class)->receive($receipt, $userId);

            return $receipt;
        });
    }
}
