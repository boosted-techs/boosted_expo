<?php

namespace App\Services;

use App\Models\AdvanceRequest;
use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\FinanceMoneyCorrection;
use App\Models\FinanceOperation;
use App\Models\FinanceSupplierBill;
use App\Models\MarketAccountability;
use App\Models\MarketTrip;
use App\Models\OrderSupply;
use App\Models\PartnerFinanceTerm;
use App\Models\PurchaseOrder;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Models\TradeCollectionCase;
use App\Models\TradeCreditException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceTradeControls
{
    public function terms(Business $business, string $type, int $id, string $currency, string $date): ?PartnerFinanceTerm
    {
        abort_unless(in_array($type, ['customer', 'supplier'], true), 422);

        return PartnerFinanceTerm::query()->where('business_id', $business->id)->where($type.'_id', $id)->where('currency', $currency)->whereDate('effective_on', '<=', $date)->orderByDesc('effective_on')->orderByDesc('id')->first();
    }

    public function saveTerms(Business $business, array $data, int $actor): PartnerFinanceTerm
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $customer = ! empty($data['customer_id']);
            abort_unless($customer !== ! empty($data['supplier_id']), 422, 'Choose one customer or supplier.');
            ($customer ? Customer::class : Supplier::class)::query()->where('business_id', $business->id)->findOrFail($data[$customer ? 'customer_id' : 'supplier_id']);
            $row = PartnerFinanceTerm::create([...$data, 'business_id' => $business->id, 'created_by' => $actor]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.partner_terms_recorded', $data['agreement'], [], ['terms_id' => $row->id, 'customer_id' => $row->customer_id, 'supplier_id' => $row->supplier_id]);

            return $row;
        }, 3);
    }

    public function credit(Business $business, Customer $customer, string $currency, string $amount, int $shipmentId, int $issuer, ?int $invoiceId = null): array
    {
        $today = now($business->timezone)->toDateString();
        $term = $this->terms($business, 'customer', $customer->id, $currency, $today);
        $exposure = BigDecimal::of($amount);
        foreach (CommercialInvoice::query()->where('business_id', $business->id)->where('customer_id', $customer->id)->where('currency', $currency)->whereIn('posting_status', ['posted', 'not_posted'])->when($invoiceId, fn ($q) => $q->whereKeyNot($invoiceId))->get() as $invoice) {
            $exposure = $exposure->plus($invoice->posting_status === 'posted' ? app(FinanceDocuments::class)->outstanding($invoice, false) : $invoice->grand_total);
        }
        $exception = null;
        if ($term?->credit_limit !== null && $exposure->isGreaterThan((string) $term->credit_limit)) {
            $exception = TradeCreditException::query()->where('business_id', $business->id)->where('customer_id', $customer->id)->where('shipment_id', $shipmentId)->where('currency', $currency)->whereDate('expires_on', '>=', $today)->where('approved_by', '!=', $issuer)->where(fn ($q) => $q->whereNull('commercial_invoice_id')->when($invoiceId, fn ($q) => $q->orWhere('commercial_invoice_id', $invoiceId)))->orderByDesc('id')->get()->first(fn ($e) => BigDecimal::of((string) $e->exposure_limit)->isGreaterThanOrEqualTo($exposure));
            abort_unless($exception, 422, 'This invoice exceeds the agreed customer credit limit. Collect the debt or obtain a separate owner-approved exception for this shipment.');
        }

        return ['terms_id' => $term?->id, 'credit_limit' => $term?->credit_limit, 'exposure' => (string) $exposure, 'currency' => $currency, 'exception_id' => $exception?->id];
    }

    public function dispatchCredit(ExportShipment $shipment, int $actor): array
    {
        $b = Business::findOrFail($shipment->business_id);
        $order = $shipment->salesOrder;
        if ($order?->order_type === 'replacement') {
            return ['replacement' => true];
        }
        $currency = $order?->currency ?? $shipment->customer->currency;
        $term = $this->terms($b, 'customer', $shipment->customer_id, $currency, now($b->timezone)->toDateString());
        if ($term?->credit_limit === null) {
            return ['credit_limit' => null, 'terms_id' => $term?->id];
        }
        $invoice = CommercialInvoice::query()->where('business_id', $b->id)->where('shipment_id', $shipment->id)->first();
        if ($invoice) {
            $amount = $invoice->posting_status === 'posted' ? app(FinanceDocuments::class)->outstanding($invoice, false) : BigDecimal::of((string) $invoice->grand_total);

            return $this->credit($b, $shipment->customer, $currency, (string) $amount, $shipment->id, $actor, $invoice->id);
        }
        abort_unless($order && $order->pricing_status === 'accepted', 422, 'This customer has a credit limit. Agree shipment pricing before dispatch so its exposure can be reviewed.');
        $amount = BigDecimal::zero();
        foreach ($shipment->allocations()->with('carton.packingJob.orderLine', 'carton.contents')->get() as $allocation) {
            $line = $allocation->carton->packingJob->orderLine;
            abort_unless($line && $line->sales_order_id === $order->id && $line->unit_price !== null, 422, 'This shipment needs priced order quantities for its credit check.');
            $quantity = app(ProcurementMath::class)->sum($allocation->carton->contents->pluck('quantity')->all());
            $amount = $amount->plus($quantity->multipliedBy((string) $line->unit_price));
        }
        $ratio = BigDecimal::of((string) $order->subtotal)->isGreaterThan(0) ? BigDecimal::of((string) $order->grand_total)->dividedBy((string) $order->subtotal, 14, RoundingMode::HalfUp) : BigDecimal::of(1);

        return $this->credit($b, $shipment->customer, $currency, (string) app(FinanceMath::class)->money($amount->multipliedBy($ratio)), $shipment->id, $actor);
    }

    public function creditException(Business $business, ExportShipment $shipment, array $data, int $actor): TradeCreditException
    {
        return DB::transaction(function () use ($business, $shipment, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $shipment = ExportShipment::query()->where('business_id', $business->id)->findOrFail($shipment->id);
            $row = TradeCreditException::create([...$data, 'business_id' => $business->id, 'customer_id' => $shipment->customer_id, 'shipment_id' => $shipment->id, 'approved_by' => $actor]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.credit_exception', $data['reason'], [], ['exception_id' => $row->id, 'shipment_id' => $shipment->id, 'exposure_limit' => $row->exposure_limit, 'currency' => $row->currency]);

            return $row;
        }, 3);
    }

    private function document(Business $business, string $type, int $id): CommercialInvoice|FinanceSupplierBill
    {
        abort_unless(in_array($type, ['customer', 'supplier'], true), 422);

        return ($type === 'customer' ? CommercialInvoice::class : FinanceSupplierBill::class)::query()->where('business_id', $business->id)->findOrFail($id);
    }

    public function followup(Business $business, string $type, int $id, array $data, int $actor): TradeCollectionCase
    {
        return DB::transaction(function () use ($business, $type, $id, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $document = $this->document($business, $type, $id);
            if (! empty($data['staff_person_id'])) {
                StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['staff_person_id']);
            }$outstanding = app(FinanceDocuments::class)->outstanding($document, false);
            abort_unless(! BigDecimal::of($data['disputed_amount'] ?? '0')->isGreaterThan($outstanding), 422, 'Disputed amount exceeds the posted unpaid balance.');
            abort_unless(($data['status'] ?? 'open') !== 'resolved' || ! $outstanding->isGreaterThan(0), 422, 'A paid collection case may be resolved; an unpaid dispute needs a documented promise, credit or settlement first.');
            $field = $type === 'customer' ? 'commercial_invoice_id' : 'supplier_bill_id';
            $case = TradeCollectionCase::query()->firstOrNew(['business_id' => $business->id, $field => $document->id]);
            $before = $case->exists ? $case->toArray() : [];
            $case->fill([...$data, 'created_by' => $case->created_by ?? $actor]);
            $case->save();
            DB::table('trade_collection_events')->insert(['business_id' => $business->id, 'case_id' => $case->id, 'actor_id' => $actor, 'action' => 'followup_recorded', 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($case->toArray(), JSON_THROW_ON_ERROR), 'note' => $data['note'], 'created_at' => now(), 'updated_at' => now()]);

            return $case;
        }, 3);
    }

    public function remind(Business $business): int
    {
        return DB::transaction(function () use ($business) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $changed = 0;
            $today = now($business->timezone)->toDateString();
            foreach (['customer', 'supplier'] as $type) {
                $model = $type === 'customer' ? CommercialInvoice::class : FinanceSupplierBill::class;
                $field = $type === 'customer' ? 'commercial_invoice_id' : 'supplier_bill_id';
                foreach ($model::query()->where('business_id', $business->id)->where('posting_status', 'posted')->where(fn ($q) => $q->whereDate('due_on', '<=', $today)->orWhereIn('id', TradeCollectionCase::query()->where('business_id', $business->id)->whereNotNull($field)->select($field)))->orderBy('id')->cursor() as $document) {
                    $balance = app(FinanceDocuments::class)->outstanding($document, false);
                    $case = TradeCollectionCase::query()->firstOrNew(['business_id' => $business->id, $field => $document->id]);
                    if (! $balance->isGreaterThan(0)) {
                        if ($case->exists && $case->status !== 'resolved') {
                            $case->update(['status' => 'resolved', 'next_action_on' => null]);
                            $this->reminderEvent($case, 'Resolved after posted debt cleared.');
                            $changed++;
                        }

                        continue;
                    }if (! $case->exists) {
                        $case->fill(['status' => 'open', 'next_action_on' => $today, 'note' => 'Payment is due. Assign collection/payment responsibility and record the next action.', 'automatic' => true]);
                        $case->save();
                        $this->reminderEvent($case, 'Internal due-payment reminder created. No external message sent.');
                        $changed++;
                    } elseif ($case->status === 'resolved') {
                        $case->update(['status' => 'open', 'next_action_on' => $today]);
                        $this->reminderEvent($case, 'Posted debt reopened after a correction or returned settlement.');
                        $changed++;
                    }
                }
            }

            return $changed;
        }, 3);
    }

    private function reminderEvent(TradeCollectionCase $case, string $note): void
    {
        DB::table('trade_collection_events')->insert(['business_id' => $case->business_id, 'case_id' => $case->id, 'actor_id' => null, 'action' => 'automatic_reminder', 'before' => '{}', 'after' => json_encode($case->toArray(), JSON_THROW_ON_ERROR), 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function requestAdvance(Business $business, array $data, int $actor): AdvanceRequest
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            foreach (['staff_person_id', 'supplier_id', 'market_trip_id'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = (int) $data[$key];
                }
            }ksort($data);
            $hash = app(FinanceWorkflows::class)->hash($data);
            if ($old = AdvanceRequest::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first()) {
                abort_unless(hash_equals($old->payload_hash, $hash), 422, 'This advance request key has different details.');

                return $old;
            }$staff = ! empty($data['staff_person_id']);
            abort_unless($staff !== ! empty($data['supplier_id']), 422, 'Choose one staff recipient or supplier.');
            ($staff ? StaffPerson::class : Supplier::class)::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data[$staff ? 'staff_person_id' : 'supplier_id']);
            if (! empty($data['market_trip_id'])) {
                $trip = MarketTrip::query()->where('business_id', $business->id)->where('status', '!=', 'closed')->findOrFail($data['market_trip_id']);
                abort_unless($staff && $trip->staff_person_id === $data['staff_person_id'], 422, 'A market request must belong to its actual buyer.');
            }

            return AdvanceRequest::create([...$data, 'business_id' => $business->id, 'reference' => 'ADV-'.Str::upper((string) Str::ulid()), 'created_by' => $actor, 'status' => 'requested', 'payload_hash' => $hash]);
        }, 3);
    }

    public function reviewAdvance(Business $business, AdvanceRequest $request, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $request, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $request = AdvanceRequest::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'requested' && $request->created_by !== $actor, 422, 'A different authorised person must review the requested advance.');
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $approve ? $actor : null, 'approved_at' => $approve ? now() : null, 'decision_note' => $note]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.advance_request_reviewed', $note, [], ['advance_request_id' => $request->id, 'status' => $request->status]);
        }, 3);
    }

    public function cancelAdvance(Business $business, AdvanceRequest $request, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $request, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $request = AdvanceRequest::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($request->id);
            abort_unless(in_array($request->status, ['requested', 'approved'], true), 422, 'Only an open advance authority can be cancelled.');
            abort_if(FinanceOperation::query()->where('business_id', $business->id)->where('advance_request_id', $request->id)->where('posting_status', 'not_posted')->exists(), 422, 'Finish or withdraw pending disbursements first.');
            $request->update(['status' => 'cancelled']);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.advance_request_cancelled', $reason, [], ['advance_request_id' => $request->id, 'previous_decision' => $request->decision_note]);
        }, 3);
    }

    public function issueCheck(Business $business, array $entry, array $data, BigDecimal $amount, string $rate, ?int $except = null): void
    {
        if (! empty($data['advance_request_id'])) {
            $request = AdvanceRequest::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['advance_request_id']);
            abort_unless(in_array($entry['kind'], ['staff_advance', 'supplier_advance'], true) && $request->status === 'approved' && $request->currency === $entry['currency'] && $request->staff_person_id === $entry['staff_person_id'] && $request->supplier_id === $entry['supplier_id'] && $request->market_trip_id === $entry['market_trip_id'] && $entry['event_on'] >= $request->approved_at->copy()->timezone($business->timezone)->toDateString(), 422, 'Use an approved request for this exact recipient, currency and market trip.');
            $used = BigDecimal::zero();
            foreach (FinanceOperation::query()->where('business_id', $business->id)->where('advance_request_id', $request->id)->when($except, fn ($q) => $q->whereKeyNot($except))->whereIn('posting_status', ['not_posted', 'posted'])->get() as $source) {
                $used = $used->plus((string) $source->amount)->minus(app(FinanceMoneyCorrections::class)->corrected($source));
            }abort_unless(! $used->plus($amount)->isGreaterThan((string) $request->amount), 422, 'Disbursements exceed the approved advance request.');
        }
        if ($entry['kind'] === 'staff_advance') {
            $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($entry['staff_person_id']);
            if ($person->advance_limit_base !== null) {
                $held = BigDecimal::zero();
                foreach (FinanceOperation::query()->where('business_id', $business->id)->where('staff_person_id', $person->id)->where('kind', 'staff_advance')->when($except, fn ($q) => $q->whereKeyNot($except))->whereIn('posting_status', ['not_posted', 'posted'])->get() as $advance) {
                    $held = $held->plus(app(FinanceMath::class)->base(app(FinanceOperations::class)->remaining($advance, false), (string) $advance->rate_to_base));
                }abort_unless(! $held->plus(app(FinanceMath::class)->base($amount, $rate))->isGreaterThan((string) $person->advance_limit_base), 422, 'This exceeds the staff member’s authorised outstanding advance limit. Clear or return earlier advances first.');
            }
        }
    }

    public function setAdvanceLimit(Business $business, StaffPerson $person, string $limit, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $person, $limit, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $person = StaffPerson::query()->where('business_id', $business->id)->findOrFail($person->id);
            $before = $person->advance_limit_base;
            $person->update(['advance_limit_base' => $limit]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.staff_advance_limit', $reason, ['limit' => $before], ['staff_person_id' => $person->id, 'limit' => $limit]);
        }, 3);
    }

    public function closeMarket(Business $business, MarketTrip $trip, string $reason, int $actor): MarketAccountability
    {
        return DB::transaction(function () use ($business, $trip, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $trip = MarketTrip::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($trip->id);
            abort_if(MarketAccountability::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->where('status', 'closed')->exists(), 422, 'This market accountability is already closed.');
            foreach (OrderSupply::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->where('kind', 'market')->where('status', 'active')->get() as $target) {
                $bought = app(ProcurementMath::class)->sum(OrderSupply::query()->where('business_id', $business->id)->where('origin_supply_id', $target->id)->where('status', 'active')->pluck('quantity')->all());
                abort_unless($bought->isEqualTo((string) $target->quantity), 422, 'Buy the target quantity or release unused market targets before closing accountability.');
            }
            $sources = [];
            $payables = [];
            foreach (PurchaseOrder::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->with('receipts.batch')->orderBy('id')->get() as $purchase) {
                abort_unless(in_array($purchase->status, ['received', 'cancelled', 'closed'], true), 422, 'Finish receiving or formally close each market purchase remainder first.');
                foreach ($purchase->receipts as $receipt) {
                    if (BigDecimal::of((string) $receipt->accepted_quantity)->isGreaterThan(0)) {
                        app(FinanceCostBasis::class)->batchBasis($receipt->batch);
                    }
                }foreach (FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->get() as $bill) {
                    abort_unless($bill->posting_status === 'posted', 422, 'Post every accepted market bill before closing accountability.');
                    $payables[] = ['bill_id' => $bill->id, 'currency' => $bill->currency, 'outstanding' => (string) app(FinanceDocuments::class)->outstanding($bill, false)];
                }$sources[] = $purchase->only(['id', 'reference', 'status', 'currency', 'quantity']);
            }
            foreach (FinanceOperation::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->whereIn('posting_status', ['not_posted', 'posted'])->get() as $money) {
                abort_unless($money->posting_status === 'posted', 422, 'Finish or withdraw every pending market money record.');
                if ($money->kind === 'staff_advance') {
                    abort_unless(app(FinanceOperations::class)->remaining($money, false)->isZero(), 422, 'Account for purchases/expenses and return unused staff cash before closing the trip.');
                }$sources[] = $money->only(['id', 'kind', 'amount', 'currency', 'snapshot_hash', 'posting_status']);
            }
            abort_if(FinanceMoneyCorrection::query()->where('business_id', $business->id)->whereIn('operation_id', FinanceOperation::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->select('id'))->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted'))->exists(), 422, 'Complete pending market money corrections first.');
            $snapshot = ['trip' => $trip->only(['id', 'reference', 'staff_person_id', 'status', 'budget', 'currency']), 'sources' => $sources, 'supplier_payables' => $payables, 'staff_reimbursements' => FinanceOperation::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->whereIn('kind', ['expense', 'market_reimbursement'])->where('posting_status', 'posted')->get()->map(fn ($e) => ['id' => $e->id, 'currency' => $e->currency, 'unpaid' => (string) app(FinanceOperations::class)->remaining($e, false)])->all()];
            $row = MarketAccountability::create(['business_id' => $business->id, 'market_trip_id' => $trip->id, 'status' => 'closed', 'data_snapshot' => $snapshot, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($snapshot), 'reason' => $reason, 'closed_by' => $actor]);
            $trip->update(['status' => 'closed']);
            AdvanceRequest::query()->where('business_id', $business->id)->where('market_trip_id', $trip->id)->where('status', 'approved')->update(['status' => 'closed']);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.market_accountability_closed', $reason, [], ['accountability_id' => $row->id, 'market_trip_id' => $trip->id]);

            return $row;
        }, 3);
    }

    public function reopenMarket(Business $business, MarketAccountability $row, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $row, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $row = MarketAccountability::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($row->id);
            abort_unless($row->status === 'closed', 422, 'Only a closed accountability can be reopened.');
            $row->update(['status' => 'reopened', 'reopened_by' => $actor, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            MarketTrip::query()->where('business_id', $business->id)->whereKey($row->market_trip_id)->update(['status' => $row->data_snapshot['trip']['status'] === 'closed' ? 'in_progress' : $row->data_snapshot['trip']['status']]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.market_accountability_reopened', $reason, [], ['accountability_id' => $row->id]);
        }, 3);
    }
}
