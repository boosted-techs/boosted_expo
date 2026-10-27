<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceSupplierBill;
use App\Models\OrderSupply;
use App\Models\PurchaseAmendment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PurchaseAmendments
{
    private function fingerprint(PurchaseOrder $purchase): string
    {
        return app(FinanceWorkflows::class)->hash([$purchase->only(['business_id', 'kind', 'product_id', 'supplier_id', 'supplier_quote_id', 'vendor_name', 'vendor_phone', 'market_location', 'purchased_on', 'quantity', 'unit_price', 'currency', 'estimated_total', 'status', 'expected_on', 'market_trip_id']), $purchase->receipts()->orderBy('id')->get()->toArray(), OrderSupply::query()->where('business_id', $purchase->business_id)->where('purchase_order_id', $purchase->id)->orderBy('id')->get()->toArray(), FinanceSupplierBill::query()->where('business_id', $purchase->business_id)->where('purchase_order_id', $purchase->id)->orderBy('id')->get()->toArray()]);
    }

    private function check(Business $business, PurchaseOrder $purchase, array $after): void
    {
        abort_unless(in_array($purchase->status, ['ordered', 'part_received', 'received'], true), 422, 'Reopen or resolve the closed/cancelled purchase first.');
        abort_if(FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->where('posting_status', 'not_posted')->exists(), 422, 'Finish or withdraw the pending original supplier bill first.');
        $received = app(ProcurementMath::class)->sum($purchase->receipts()->pluck('received_quantity')->all());
        $accepted = app(ProcurementMath::class)->sum($purchase->receipts()->pluck('accepted_quantity')->all());
        $billed = app(ProcurementMath::class)->sum(FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->where('posting_status', 'posted')->pluck('accepted_quantity')->all());
        abort_unless(! $received->isGreaterThan($after['quantity']), 422, 'Agreed purchase quantity cannot be less than goods already received, including rejects. Use return/shortage evidence for those goods.');
        $committed = app(ProcurementMath::class)->sum(OrderSupply::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->where('status', 'active')->pluck('quantity')->all());
        abort_unless(! $committed->isGreaterThan($after['quantity']), 422, 'Release or reduce affected order commitments before reducing this purchase.');
        if (! BigDecimal::of((string) $purchase->unit_price)->isEqualTo($after['unit_price'])) {
            abort_unless($billed->isGreaterThanOrEqualTo($accepted), 422, 'Post the original received goods bills before changing the price for future receipts. Existing bills keep their agreed price.');
        }
        $supplier = $after['supplier_id'] ?? null;
        if ($supplier) {
            Supplier::query()->where('business_id', $business->id)->where('active', true)->findOrFail($supplier);
        }
        if ((int) $supplier !== (int) $purchase->supplier_id || ($after['vendor_name'] ?? null) !== $purchase->vendor_name) {
            abort_unless($received->isZero() && $committed->isZero() && ! FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->exists(), 422, 'A received, committed, billed or advance-funded purchase keeps its original supplier. Close its unused remainder and create the replacement supplier purchase.');
        }
    }

    public function propose(Business $business, PurchaseOrder $purchase, array $data, int $actor): PurchaseAmendment
    {
        return DB::transaction(function () use ($business, $purchase, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            $purchase = PurchaseOrder::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($purchase->id);
            ksort($data);
            $hash = app(FinanceWorkflows::class)->hash([$purchase->id, $data]);
            if ($old = PurchaseAmendment::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first()) {
                abort_unless($old->payload_hash === $hash, 409, 'This amendment key has different agreement details.');

                return $old;
            }
            abort_if(PurchaseAmendment::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->where('status', 'proposed')->exists(), 422, 'Review the pending purchase amendment first.');
            $after = ['quantity' => (string) BigDecimal::of($data['quantity'])->toScale(3), 'unit_price' => (string) BigDecimal::of($data['unit_price'])->toScale(4), 'expected_on' => $data['expected_on'] ?? null, 'supplier_id' => $purchase->kind === 'supplier' ? (int) ($data['supplier_id'] ?? $purchase->supplier_id) : null, 'vendor_name' => $purchase->kind === 'supplier' ? null : ($data['vendor_name'] ?? $purchase->vendor_name), 'vendor_phone' => $purchase->kind === 'supplier' ? null : ($data['vendor_phone'] ?? $purchase->vendor_phone)];
            $this->check($business, $purchase, $after);
            $proof = ['before' => $purchase->only(array_keys($after)), 'after' => $after];

            return PurchaseAmendment::create(['business_id' => $business->id, 'purchase_order_id' => $purchase->id, 'status' => 'proposed', 'data_snapshot' => $proof, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($proof), 'base_hash' => $this->fingerprint($purchase), 'reason' => $data['reason'], 'agreement_reference' => $data['agreement_reference'], 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }

    public function review(Business $business, PurchaseAmendment $amendment, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $amendment, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'procurement');
            $amendment = PurchaseAmendment::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($amendment->id);
            abort_unless($amendment->status === 'proposed' && $amendment->created_by !== $actor, 422, 'An independent owner or administrator must review the proposed agreement.');
            abort_unless(hash_equals($amendment->snapshot_hash, app(FinanceWorkflows::class)->hash($amendment->data_snapshot)), 422, 'The proposed agreement changed.');
            $purchase = PurchaseOrder::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($amendment->purchase_order_id);
            if ($approve) {
                abort_unless($this->fingerprint($purchase) === $amendment->base_hash, 422, 'Purchase, receipts, bills or commitments changed. Reject and prepare a current amendment.');
                $after = $amendment->data_snapshot['after'];
                $this->check($business, $purchase, $after);
                $received = app(ProcurementMath::class)->sum($purchase->receipts()->pluck('received_quantity')->all());
                $purchase->update([...$after, 'supplier_quote_id' => null, 'estimated_total' => app(ProcurementMath::class)->amount($after['quantity'], $after['unit_price']), 'status' => $received->isEqualTo($after['quantity']) ? 'received' : ($received->isGreaterThan(0) ? 'part_received' : 'ordered')]);
            }
            $amendment->update(['status' => $approve ? 'applied' : 'rejected', 'reviewed_by' => $actor, 'review_note' => $note, 'reviewed_at' => now()]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'purchase.agreement_reviewed', $note, $amendment->data_snapshot['before'], ['amendment_id' => $amendment->id, 'decision' => $amendment->status, 'agreement' => $amendment->agreement_reference, 'after' => $amendment->data_snapshot['after']]);
        }, 3);
    }
}
