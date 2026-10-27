<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PartnerFinanceTerm;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationLine;
use App\Models\SalesRequest;
use App\Models\SalesRequestItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesWorkflow
{
    public function createRequest(int $businessId, array $data, ?int $userId): SalesRequest
    {
        return DB::transaction(function () use ($businessId, $data, $userId) {
            Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $submissionHash = hash('sha256', json_encode(collect($data)->except('submission_key')->all(), JSON_THROW_ON_ERROR));
            if (! empty($data['submission_key'])) {
                $old = SalesRequest::query()->where('business_id', $businessId)->where('submission_key', $data['submission_key'])->first();
                if ($old) {
                    abort_unless($old->submission_hash === $submissionHash && $old->customer_id === (int) $data['customer_id'], 409, 'This submission key was already used for different demand.');

                    return $old;
                }
            }
            Customer::query()->where('business_id', $businessId)->where('active', true)
                ->findOrFail($data['customer_id']);
            $products = Product::query()->where('business_id', $businessId)->where('active', true)
                ->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
            if ($products->count() !== count($data['items'])) {
                throw ValidationException::withMessages(['items' => 'Select valid, distinct products from this business.']);
            }
            $request = SalesRequest::create([
                'business_id' => $businessId,
                'reference' => 'CRQ-'.Str::upper((string) Str::ulid()),
                'customer_id' => $data['customer_id'], 'required_on' => $data['required_on'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => 'open', 'created_by' => $userId,
                'source' => $data['source'] ?? 'staff', 'buyer_reference' => $data['buyer_reference'] ?? null, 'destination' => $data['destination'] ?? null, 'submission_key' => $data['submission_key'] ?? null, 'submission_hash' => $submissionHash,
            ]);
            foreach ($data['items'] as $line) {
                SalesRequestItem::create([
                    'business_id' => $businessId, 'sales_request_id' => $request->id,
                    'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                ]);
            }

            return $request;
        });
    }

    public function draft(int $businessId, int $requestId, int $userId): SalesQuotation
    {
        return DB::transaction(function () use ($businessId, $requestId, $userId) {
            Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $request = SalesRequest::query()->where('business_id', $businessId)
                ->lockForUpdate()->findOrFail($requestId);
            abort_unless(in_array($request->status, ['open', 'quoted', 'approved'], true), 422,
                'A confirmed customer request cannot be revised.');
            $operational = $request->order()->first();
            if ($operational && ($operational->pricing_status !== 'unpriced' || $operational->status !== 'confirmed')) {
                abort(422, 'This customer request already has agreed pricing or is closed.');
            }
            $latest = $request->quotations()->orderByDesc('revision')->first();
            if ($latest?->status === 'draft') {
                return $latest;
            }
            $revision = ($latest?->revision ?? 0) + 1;
            $quote = SalesQuotation::create([
                'business_id' => $businessId, 'sales_request_id' => $request->id,
                'reference' => $request->reference.'-Q'.str_pad((string) $revision, 3, '0', STR_PAD_LEFT),
                'revision' => $revision, 'status' => 'draft',
                'currency' => $latest?->currency ?? $request->customer->currency,
                'incoterm' => $latest?->incoterm,
                'valid_until' => $latest?->valid_until,
                'discount_percent' => $latest?->discount_percent ?? 0,
                'tax_percent' => $latest?->tax_percent ?? 0,
                'subtotal' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'grand_total' => '0',
                'notes' => $latest?->notes, 'created_by' => $userId,
            ]);
            $prior = $latest?->lines->keyBy('product_id');
            foreach ($request->items->filter(fn ($item) => BigDecimal::of((string) $item->quantity)->isGreaterThan(0)) as $item) {
                $saved = $prior?->get($item->product_id);
                SalesQuotationLine::create([
                    'business_id' => $businessId, 'sales_quotation_id' => $quote->id,
                    'product_id' => $item->product_id,
                    'quantity' => $operational ? $item->quantity : ($saved?->quantity ?? $item->quantity),
                    'unit_price' => $saved?->unit_price ?? '0',
                    'line_total' => $saved?->line_total ?? '0',
                    'product_name' => $saved?->product_name ?? $item->product->name,
                    'product_sku' => $saved?->product_sku ?? $item->product->sku,
                    'unit' => $saved?->unit ?? $item->product->base_unit,
                ]);
            }

            return $quote;
        });
    }

    public function updateDraft(SalesQuotation $quote, array $data): SalesQuotation
    {
        return DB::transaction(function () use ($quote, $data) {
            $quote = SalesQuotation::query()->where('business_id', $quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            abort_unless($quote->status === 'draft', 422, 'Only draft quotations can be edited.');
            $request = $quote->salesRequest()->with('items')->firstOrFail();
            $business = Business::findOrFail($quote->business_id);
            $override = strtoupper($data['currency']) !== $request->customer->currency;
            if ($override && mb_strlen(trim($data['currency_override_reason'] ?? '')) < 10) {
                throw ValidationException::withMessages(['currency_override_reason' => 'Explain the agreed change from the customer default currency.']);
            }
            $expected = $request->items->filter(fn ($item) => BigDecimal::of((string) $item->quantity)->isGreaterThan(0))->keyBy('product_id');
            $seen = [];
            foreach ($data['items'] as $line) {
                $id = (int) $line['product_id'];
                if (isset($seen[$id]) || ! $expected->has($id)) {
                    throw ValidationException::withMessages(['items' => 'Each offered product must match the customer request exactly once.']);
                }
                $seen[$id] = true;
                if (BigDecimal::of((string) $line['quantity'])->isGreaterThan(
                    BigDecimal::of((string) $expected[$id]->quantity))) {
                    throw ValidationException::withMessages(['items' => 'An offered quantity exceeds the requested quantity.']);
                }
            }
            if (count($seen) !== $expected->count()) {
                throw ValidationException::withMessages(['items' => 'Provide a price and quantity for every requested product.']);
            }
            $totals = app(SalesPricing::class)->totals($data['items'],
                (string) ($data['discount_percent'] ?? 0), (string) ($data['tax_percent'] ?? 0));
            foreach ($totals['lines'] as $line) {
                SalesQuotationLine::query()->where('business_id', $quote->business_id)
                    ->where('sales_quotation_id', $quote->id)
                    ->where('product_id', $line['product_id'])
                    ->update([
                        'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                        'line_total' => $line['line_total'],
                    ]);
            }
            $defaultTerms = app(FinanceTradeControls::class)->terms($business, 'customer', $request->customer_id, strtoupper($data['currency']), now($business->timezone)->toDateString());
            $quote->update([
                'currency' => strtoupper($data['currency']),
                'currency_override_reason' => $override ? $data['currency_override_reason'] : null,
                'pricing_exchange_rate_id' => isset($data['pricing_exchange_rate_id']) ? (int) $data['pricing_exchange_rate_id'] : null,
                'partner_finance_term_id' => $defaultTerms?->id, 'payment_terms_days' => $data['payment_terms_days'] ?? $defaultTerms?->payment_days,
                'incoterm' => $data['incoterm'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'discount_percent' => $data['discount_percent'] ?? 0,
                'tax_percent' => $data['tax_percent'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            return $quote->refresh();
        });
    }

    public function issue(SalesQuotation $quote, int $userId): void
    {
        DB::transaction(function () use ($quote, $userId) {
            Business::query()->whereKey($quote->business_id)->lockForUpdate()->firstOrFail();
            $request = SalesRequest::query()->where('business_id', $quote->business_id)
                ->lockForUpdate()->findOrFail($quote->sales_request_id);
            $quote = SalesQuotation::query()->where('business_id', $quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            abort_unless($quote->status === 'draft' && $request->status !== 'confirmed', 422,
                'This quotation cannot be issued.');
            $latest = $request->quotations()->max('revision');
            abort_unless($latest === $quote->revision, 422, 'A newer quotation revision already exists.');
            if ($quote->valid_until && $quote->valid_until->toDateString() < now(Business::query()->findOrFail($quote->business_id)->timezone)->toDateString()) {
                throw ValidationException::withMessages(['valid_until' => 'Validity date has passed.']);
            }
            foreach ($quote->lines as $line) {
                if (BigDecimal::of((string) $line->unit_price)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['items' => 'Every quoted product needs a positive unit price.']);
                }
            }
            $business = Business::query()->findOrFail($quote->business_id);
            $rate = app(FinanceRates::class)->rate($business, $quote->currency, $quote->pricing_exchange_rate_id, now($business->timezone)->toDateString());
            $pricing = ['partner_finance_terms' => $quote->partner_finance_term_id ? PartnerFinanceTerm::query()->where('business_id', $quote->business_id)->findOrFail($quote->partner_finance_term_id)->only(['id', 'currency', 'payment_days', 'effective_on', 'agreement']) : null, 'currency_override_reason' => $quote->currency_override_reason, 'currency' => $quote->currency, 'base_currency' => $business->base_currency, 'exchange_rate_id' => $quote->pricing_exchange_rate_id, 'rate_to_base' => $rate, 'pricing_on' => now($business->timezone)->toDateString(), 'base_grand_total' => (string) app(FinanceMath::class)->base((string) $quote->grand_total, $rate), 'payment_terms_days' => $quote->payment_terms_days];
            $quote->update(['pricing_snapshot' => $pricing]);
            $request->quotations()->where('status', 'issued')->update(['status' => 'superseded']);
            $quote->update(['status' => 'issued', 'issued_by' => $userId, 'issued_at' => now()]);
            // Preserve exactly what the buyer was offered, including names and prices.
            $quote->refresh()->load(['lines.product', 'salesRequest.customer']);
            $exporter = Business::query()->findOrFail($quote->business_id);
            $html = view('sales.quotes.print', ['quote' => $quote, 'exporter' => $exporter->name])->render();
            $quote->update(['html_snapshot' => $html, 'sha256' => hash('sha256', $html)]);
            $request->update(['status' => 'quoted']);
        });
    }

    public function confirm(SalesQuotation $quote, string $buyerConfirmation, ?int $userId): SalesOrder
    {
        return DB::transaction(function () use ($quote, $buyerConfirmation, $userId) {
            Business::query()->whereKey($quote->business_id)->lockForUpdate()->firstOrFail();
            $request = SalesRequest::query()->where('business_id', $quote->business_id)
                ->lockForUpdate()->findOrFail($quote->sales_request_id);
            $quote = SalesQuotation::query()->where('business_id', $quote->business_id)
                ->lockForUpdate()->findOrFail($quote->id);
            $existing = $request->order()->first();
            if ($existing) {
                abort_unless($existing->pricing_status === 'unpriced' && $existing->status === 'confirmed' && $quote->status === 'issued' && $request->status === 'quoted', 422, 'This order already has agreed pricing or is closed.');
                abort_unless(! $quote->valid_until || $quote->valid_until->toDateString() >= now(Business::query()->findOrFail($quote->business_id)->timezone)->toDateString(), 422, 'The quotation has expired.');
                $expected = $existing->lines->filter(fn ($line) => BigDecimal::of((string) $line->quantity)->isGreaterThan(0))->keyBy('product_id');
                abort_unless($quote->lines->count() === $expected->count(), 422, 'Pricing must cover the existing operational order.');
                foreach ($quote->lines as $line) {
                    abort_unless(isset($expected[$line->product_id]) && BigDecimal::of((string) $line->quantity)->isEqualTo(BigDecimal::of((string) $expected[$line->product_id]->quantity)), 422, 'Pricing must retain approved operational quantities; use a controlled quantity amendment separately.');
                    $expected[$line->product_id]->update(['unit_price' => $line->unit_price, 'line_total' => $line->line_total]);
                }
                $existing->update(['sales_quotation_id' => $quote->id, 'pricing_status' => 'accepted', 'currency' => $quote->currency, 'incoterm' => $quote->incoterm, 'discount_percent' => $quote->discount_percent, 'tax_percent' => $quote->tax_percent, 'subtotal' => $quote->subtotal, 'discount_amount' => $quote->discount_amount, 'tax_amount' => $quote->tax_amount, 'grand_total' => $quote->grand_total, 'buyer_confirmation' => $buyerConfirmation]);
                $quote->update(['status' => 'accepted']);
                $request->update(['status' => 'confirmed']);
                app(OrderOperations::class)->event($existing, $userId, 'pricing.accepted', $buyerConfirmation, ['quotation_id' => $quote->id]);

                return $existing;
            }
            abort_unless($quote->status === 'issued' && $request->status === 'quoted', 422,
                'Only an issued, current quotation can be confirmed.');
            abort_unless(! $quote->valid_until || $quote->valid_until->toDateString() >= now(Business::query()->findOrFail($quote->business_id)->timezone)->toDateString(), 422,
                'The quotation has expired. Issue a new revision.');
            $order = SalesOrder::create([
                'business_id' => $quote->business_id, 'reference' => 'SO-'.Str::upper((string) Str::ulid()),
                'sales_request_id' => $request->id, 'sales_quotation_id' => $quote->id,
                'customer_id' => $request->customer_id, 'status' => 'confirmed', 'pricing_status' => 'accepted', 'required_on' => $request->required_on, 'destination' => $request->destination,
                'currency' => $quote->currency, 'incoterm' => $quote->incoterm,
                'discount_percent' => $quote->discount_percent, 'tax_percent' => $quote->tax_percent,
                'subtotal' => $quote->subtotal, 'discount_amount' => $quote->discount_amount,
                'tax_amount' => $quote->tax_amount, 'grand_total' => $quote->grand_total,
                'buyer_confirmation' => $buyerConfirmation, 'notes' => $quote->notes,
                'confirmed_by' => $userId, 'confirmed_at' => now(),
            ]);
            foreach ($quote->lines as $line) {
                SalesOrderLine::create([
                    'business_id' => $quote->business_id, 'sales_order_id' => $order->id,
                    'product_id' => $line->product_id, 'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price, 'line_total' => $line->line_total,
                    'product_name' => $line->product_name ?? $line->product->name,
                    'product_sku' => $line->product_sku ?? $line->product->sku,
                    'unit' => $line->unit ?? $line->product->base_unit,
                ]);
            }
            $quote->update(['status' => 'accepted']);
            $request->update(['status' => 'confirmed']);

            return $order;
        });
    }
}
