<?php

namespace App\Services;

use App\Models\Business;
use App\Models\OrderAmendment;
use App\Models\Product;
use App\Models\QuotationAccess;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesRequestItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderAmendments
{
    private function base(SalesOrder $order): array
    {
        return ['request_items' => $order->salesRequest->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'product_id', 'quantity', 'notes']))->all(), 'order' => $order->only(['id', 'business_id', 'customer_id', 'status', 'order_type', 'pricing_status', 'currency', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'grand_total', 'incoterm', 'required_on', 'destination', 'buyer_confirmation', 'pricing_snapshot']), 'lines' => $order->lines()->orderBy('id')->get()->map(fn ($line) => $line->only(['id', 'product_id', 'quantity', 'unit_price', 'line_total', 'closed_quantity', 'product_name', 'product_sku', 'unit']))->all()];
    }

    private function capacity(SalesOrder $order, array $lines): void
    {
        foreach ($lines as $row) {
            if (! $row['id']) {
                continue;
            }$line = SalesOrderLine::query()->where('business_id', $order->business_id)->where('sales_order_id', $order->id)->findOrFail($row['id']);
            $s = app(OrderSourcing::class)->summary($line);
            $covered = BigDecimal::of($s['packed'])->plus($s['reserved'])->plus($s['quality_held'])->plus($s['expected'])->plus($s['market_pending'])->plus($row['closed_quantity']);
            abort_unless(! $covered->isGreaterThan($row['quantity']), 422, 'Release unused source commitments/reservations or complete the required repacking before reducing this order quantity. Packed goods and closed quantities remain accounted for.');
        }
    }

    public function propose(Business $b, SalesOrder $order, array $data, int $actor): OrderAmendment
    {
        return DB::transaction(function () use ($b, $order, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'sales');
            $order = SalesOrder::query()->where('business_id', $b->id)->with('lines')->lockForUpdate()->findOrFail($order->id);
            ksort($data);
            $hash = app(FinanceWorkflows::class)->hash([$order->id, $data]);
            if ($old = OrderAmendment::query()->where('business_id', $b->id)->where('request_key', $data['request_key'])->first()) {
                abort_unless(hash_equals($old->payload_hash, $hash), 422, 'This amendment request already has different changes.');

                return $old;
            }abort_unless($order->status === 'confirmed' && $order->order_type === 'sale', 422, 'Amend an active sales order. Replacement demand stays linked to its case.');
            abort_if(OrderAmendment::query()->where('business_id', $b->id)->where('sales_order_id', $order->id)->whereIn('status', ['proposed', 'approved_for_buyer'])->exists(), 422, 'Review, reject or withdraw the existing order amendment first.');
            $priced = $order->pricing_status === 'accepted';
            $lines = [];
            $seen = [];
            $existing = [];
            foreach ($data['lines'] as $input) {
                $line = ! empty($input['id']) ? $order->lines->firstWhere('id', (int) $input['id']) : null;
                abort_unless(empty($input['id']) || $line, 404);
                $product = Product::query()->where('business_id', $b->id)->findOrFail($line?->product_id ?? $input['product_id']);
                abort_unless($line || $product->active, 422, 'Choose an active product for added demand.');
                abort_if(isset($seen[$product->id]), 422, 'List each product once in the amended order.');
                $seen[$product->id] = true;
                if ($line) {
                    $existing[] = $line->id;
                }$qty = BigDecimal::of($input['quantity']);
                $closed = BigDecimal::of((string) ($input['closed_quantity'] ?? $line?->closed_quantity ?? '0'));
                abort_unless(! $qty->isLessThan(0) && ! $closed->isLessThan(0) && ! $closed->isGreaterThan($qty), 422, 'Amended quantities and closed portions must reconcile.');
                abort_unless($priced || empty($input['unit_price']), 422, 'Use the quotation task to agree prices for unpriced demand.');
                $price = $priced ? (string) BigDecimal::of($input['unit_price'] ?? '0')->toScale(4, RoundingMode::HalfUp) : null;
                abort_unless(! $priced || BigDecimal::of($price)->isGreaterThan(0), 422, 'Priced sales lines need an agreed positive unit price. Use a case-linked replacement for no-charge demand.');
                $lines[] = ['id' => $line?->id, 'product_id' => $product->id, 'quantity' => (string) $qty, 'closed_quantity' => (string) $closed, 'unit_price' => $price, 'product_name' => $line?->product_name ?? $product->name, 'product_sku' => $line?->product_sku ?? $product->sku, 'unit' => $line?->unit ?? $product->base_unit];
            }
            abort_unless(count($existing) === $order->lines->count(), 422, 'Keep every historical order line. Set an unused line to zero after releasing its sources rather than deleting its history.');
            abort_unless(collect($lines)->contains(fn ($r) => BigDecimal::of($r['quantity'])->isGreaterThan(0)), 422, 'An order must retain positive demand. Use the formal closure task to cancel all remaining work.');
            $this->capacity($order, $lines);
            $totals = $priced ? app(SalesPricing::class)->totals($lines, (string) ($data['discount_percent'] ?? $order->discount_percent ?? 0), (string) ($data['tax_percent'] ?? $order->tax_percent ?? 0)) : null;
            $rate = $priced ? app(FinanceRates::class)->rate($b, $order->currency, isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null, now($b->timezone)->toDateString()) : null;
            $snapshot = ['before' => $this->base($order), 'lines' => $lines, 'totals' => $totals, 'currency' => $order->currency, 'priced' => $priced, 'required_on' => $data['required_on'] ?? $order->required_on?->toDateString(), 'destination' => $data['destination'] ?? $order->destination, 'discount_percent' => $data['discount_percent'] ?? $order->discount_percent ?? '0', 'tax_percent' => $data['tax_percent'] ?? $order->tax_percent ?? '0', 'pricing_fx' => ['currency' => $order->currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $data['exchange_rate_id'] ?? null, 'effective_on' => now($b->timezone)->toDateString()], 'buyer_mode' => $data['buyer_mode'], 'buyer_external' => $data['buyer_mode'] === 'external' ? array_intersect_key($data, array_flip(['buyer_name', 'buyer_reference', 'buyer_evidence'])) : null];
            $row = OrderAmendment::create(['business_id' => $b->id, 'sales_order_id' => $order->id, 'reference' => 'AMD-'.Str::upper((string) Str::ulid()), 'status' => 'proposed', 'data_snapshot' => $snapshot, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($snapshot), 'base_hash' => app(FinanceWorkflows::class)->hash($snapshot['before']), 'reason' => $data['reason'], 'created_by' => $actor, 'buyer_mode' => $data['buyer_mode'], 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
            app(OrderOperations::class)->event($order, $actor, 'amendment.proposed', $data['reason'], ['amendment_id' => $row->id]);

            return $row;
        }, 3);
    }

    private function verify(OrderAmendment $row): array
    {
        abort_unless(hash_equals($row->snapshot_hash, app(FinanceWorkflows::class)->hash($row->data_snapshot)), 422, 'The proposed order changes failed their evidence check.');
        $order = SalesOrder::query()->where('business_id', $row->business_id)->lockForUpdate()->findOrFail($row->sales_order_id);
        abort_unless($order->status === 'confirmed' && $order->order_type === 'sale' && hash_equals($row->base_hash, app(FinanceWorkflows::class)->hash($this->base($order))), 422, 'The commercial order changed after this proposal. Withdraw it and prepare the current agreed amendment.');

        return [$order, $row->data_snapshot];
    }

    private function apply(OrderAmendment $row, SalesOrder $order, array $d, ?int $actor): void
    {
        $this->capacity($order, $d['lines']);
        foreach ($d['lines'] as $line) {
            $fields = array_diff_key($line, ['id' => true]);
            $fields['line_total'] = $d['priced'] ? (string) app(SalesPricing::class)->money(BigDecimal::of($line['quantity'])->multipliedBy($line['unit_price'])) : null;
            if ($line['id']) {
                SalesOrderLine::query()->where('business_id', $order->business_id)->where('sales_order_id', $order->id)->findOrFail($line['id'])->update($fields);
            } else {
                SalesOrderLine::create([...$fields, 'business_id' => $order->business_id, 'sales_order_id' => $order->id]);
            }
        }
        foreach ($d['lines'] as $line) {
            SalesRequestItem::query()->updateOrCreate(['business_id' => $order->business_id, 'sales_request_id' => $order->sales_request_id, 'product_id' => $line['product_id']], ['quantity' => $line['quantity']]);
        }
        if (! $d['priced']) {
            $quotes = SalesQuotation::query()->where('business_id', $order->business_id)->where('sales_request_id', $order->sales_request_id)->whereIn('status', ['draft', 'issued'])->get();
            foreach ($quotes as $quote) {
                $quote->update(['status' => 'superseded']);
                QuotationAccess::query()->where('business_id', $order->business_id)->where('sales_quotation_id', $quote->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }$order->salesRequest->update(['status' => 'approved']);
        }
        $changes = ['required_on' => $d['required_on'], 'destination' => $d['destination'], 'buyer_confirmation' => mb_substr('Amended by '.$row->reference.' / '.$row->buyer_reference, 0, 160)];
        if ($d['priced']) {
            $changes = [...$changes, ...array_intersect_key($d['totals'], array_flip(['subtotal', 'discount_amount', 'tax_amount', 'grand_total'])), 'discount_percent' => $d['discount_percent'], 'tax_percent' => $d['tax_percent'], 'pricing_snapshot' => ['amendment_id' => $row->id, 'amendment_reference' => $row->reference, 'pricing_fx' => $d['pricing_fx'], 'base_grand_total' => (string) app(FinanceMath::class)->base($d['totals']['grand_total'], $d['pricing_fx']['rate_to_base']), 'buyer_name' => $row->buyer_name, 'buyer_reference' => $row->buyer_reference, 'buyer_decided_at' => $row->buyer_decided_at?->toIso8601String()]];
        }$order->update($changes);
        $row->update(['status' => 'applied', 'applied_at' => now(), 'revoked_at' => now()]);
        app(OrderOperations::class)->event($order, $actor, 'amendment.applied', $row->reason, ['amendment_id' => $row->id, 'before' => $d['before'], 'after' => $this->base($order->fresh()), 'reviewed_by' => $row->reviewed_by], 'Order quantities / terms updated under agreement '.$row->buyer_reference.'.');
    }

    public function review(Business $b, OrderAmendment $row, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($b, $row, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'sales');
            $row = OrderAmendment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($row->id);
            abort_unless($row->status === 'proposed' && $row->created_by !== $actor, 422, 'A different authorised person must review this proposed order amendment.');
            if (! $approve) {
                $row->update(['status' => 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note, 'revoked_at' => now()]);

                return;
            }[$order,$d] = $this->verify($row);
            $this->capacity($order, $d['lines']);
            $row->update(['reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
            if ($row->buyer_mode === 'link') {
                $row->update(['status' => 'approved_for_buyer']);
            } else {
                $buyer = $d['buyer_external'];
                abort_unless(mb_strlen(trim($buyer['buyer_evidence'] ?? '')) >= 20 && ! empty($buyer['buyer_name']) && ! empty($buyer['buyer_reference']), 422, 'Record the buyer’s actual external agreement before applying these changes.');
                $row->update([...$buyer, 'buyer_decided_at' => now()]);
                $this->apply($row, $order, $d, $actor);
            }
        }, 3);
    }

    public function link(Business $b, OrderAmendment $row, int $actor): string
    {
        return DB::transaction(function () use ($b, $row, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'sales');
            $row = OrderAmendment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($row->id);
            abort_unless($row->status === 'approved_for_buyer' && $row->buyer_mode === 'link', 422, 'Independently approve this proposal before sharing it for buyer agreement.');
            $this->verify($row);
            $plain = Str::random(64);
            $row->update(['token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(14), 'revoked_at' => null]);
            app(OrderOperations::class)->event($row->order, $actor, 'amendment.link_created', 'Buyer review link generated without sending a message.', ['amendment_id' => $row->id]);

            return $plain;
        }, 3);
    }

    public function resolve(string $token): array
    {
        $row = OrderAmendment::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $business = Business::query()->where('is_active', true)->findOrFail($row->business_id);
        $order = SalesOrder::query()->where('business_id', $business->id)->with('customer')->findOrFail($row->sales_order_id);
        abort_unless($order->customer?->active, 404);
        abort_if(! $row->expires_at || $row->expires_at->isPast() || ($row->revoked_at && ! in_array($row->status, ['applied', 'buyer_rejected'], true)), 410, 'This order change link expired or was withdrawn.');
        abort_unless(in_array($row->status, ['approved_for_buyer', 'applied', 'buyer_rejected'], true), 410, 'This order change is no longer available.');

        return compact('row', 'business', 'order');
    }

    public function decide(string $token, array $data): void
    {
        $r = $this->resolve($token);
        DB::transaction(function () use ($r, $token, $data) {
            app(OrderOperations::class)->lockForWork($r['business']->id, 'sales');
            $resolved = $this->resolve($token);
            $row = OrderAmendment::query()->where('business_id', $r['business']->id)->lockForUpdate()->findOrFail($resolved['row']->id);
            if (in_array($row->status, ['applied', 'buyer_rejected'], true)) {
                abort_unless(($row->status === 'applied' ? 'accepted' : 'rejected') === $data['decision'] && $row->buyer_name === $data['buyer_name'] && $row->buyer_reference === $data['buyer_reference'], 409, 'A buyer decision is already recorded.');

                return;
            }[$order,$d] = $this->verify($row);
            $row->update(['buyer_name' => $data['buyer_name'], 'buyer_reference' => $data['buyer_reference'], 'buyer_evidence' => 'Decision submitted through the private order amendment link.', 'buyer_decided_at' => now()]);
            if ($data['decision'] === 'accepted') {
                $this->apply($row, $order, $d, null);
            } else {
                $row->update(['status' => 'buyer_rejected', 'revoked_at' => now()]);
                app(OrderOperations::class)->event($order, null, 'amendment.buyer_rejected', 'Buyer declined the proposed order changes.', ['amendment_id' => $row->id], 'Proposed order change declined by the buyer.');
            }
        }, 3);
    }

    public function withdraw(Business $b, OrderAmendment $row, string $reason, int $actor): void
    {
        DB::transaction(function () use ($b, $row, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'sales');
            $row = OrderAmendment::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($row->id);
            abort_unless(in_array($row->status, ['proposed', 'approved_for_buyer'], true), 422, 'This order change is already decided.');
            $row->update(['status' => 'withdrawn', 'revoked_at' => now()]);
            app(OrderOperations::class)->event($row->order, $actor, 'amendment.withdrawn', $reason, ['amendment_id' => $row->id]);
        }, 3);
    }
}
