<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBillReceipt;
use App\Models\FinanceCreditNote;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTradeCredit;
use App\Models\MarketAccountability;
use App\Models\PurchaseOrder;
use App\Models\TransportLeg;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceDocuments
{
    public function outstandingQuery(Business $business, bool $customer): Builder
    {
        $model = $customer ? CommercialInvoice::class : FinanceSupplierBill::class;
        $table = (new $model)->getTable();
        $column = $customer ? 'commercial_invoice_id' : 'supplier_bill_id';
        $total = $customer ? 'grand_total' : 'total';
        $payments = FinancePayment::query()->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn($column, $table.'.id')->whereColumn('business_id', $table.'.business_id')
            ->whereIn('status', ['pending', 'posted']);
        $adjustments = FinanceBalanceAdjustment::query()->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn($column, $table.'.id')->whereColumn('business_id', $table.'.business_id')
            ->whereIn('posting_status', ['not_posted', 'posted']);
        $advances = FinanceOperation::query()->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn($column, $table.'.id')->whereColumn('business_id', $table.'.business_id')
            ->whereIn('posting_status', ['not_posted', 'posted']);
        $returns = FinancePartialBankReturn::query()->selectRaw('COALESCE(SUM(finance_partial_bank_returns.amount), 0)')
            ->join('finance_payments as paid', function ($join) {
                $join->on('paid.id', '=', 'finance_partial_bank_returns.source_id')
                    ->on('paid.business_id', '=', 'finance_partial_bank_returns.business_id');
            })
            ->where('finance_partial_bank_returns.source_type', 'payment')
            ->where('finance_partial_bank_returns.posting_status', 'posted')->where('paid.status', 'posted')
            ->whereColumn('paid.'.$column, $table.'.id')->whereColumn('paid.business_id', $table.'.business_id');

        $advanceReturns = DB::table('finance_money_corrections as returned')->join('finance_operations as original', function ($q) {
            $q->on('original.id', '=', 'returned.operation_id')->on('original.business_id', '=', 'returned.business_id');
        })->join('finance_workflows as workflow', function ($q) {
            $q->on('workflow.id', '=', 'returned.workflow_id')->on('workflow.business_id', '=', 'returned.business_id');
        })->where('workflow.posting_status', 'posted')->where('original.posting_status', 'posted')->whereColumn('original.'.$column, $table.'.id')->whereColumn('returned.business_id', $table.'.business_id')->selectRaw('COALESCE(SUM(returned.amount),0)');

        $tradeCredits = DB::table('finance_trade_credits as tc')->join('finance_workflows as w', 'w.id', '=', 'tc.workflow_id')->whereColumn('tc.business_id', $table.'.business_id')->whereColumn('w.business_id', $table.'.business_id')->whereColumn('tc.'.$column, $table.'.id')->whereIn('w.posting_status', ['posted', 'not_posted'])->selectRaw('COALESCE(SUM(tc.applied_amount),0)');
        $tradeUses = DB::table('finance_trade_credit_uses as tu')->join('finance_workflows as w', 'w.id', '=', 'tu.workflow_id')->whereColumn('tu.business_id', $table.'.business_id')->whereColumn('w.business_id', $table.'.business_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_workflow_corrections as fc')->join('finance_workflows as cw', 'cw.id', '=', 'fc.workflow_id')->whereColumn('fc.business_id', 'tu.business_id')->whereColumn('fc.original_workflow_id', 'tu.workflow_id')->where('cw.posting_status', 'posted'))->whereColumn('tu.'.$column, $table.'.id')->whereIn('w.posting_status', ['posted', 'not_posted'])->selectRaw('COALESCE(SUM(tu.amount),0)');

        $openingUses = DB::table('finance_opening_item_uses as ou')->join('finance_workflows as ow', 'ow.id', '=', 'ou.workflow_id')->whereColumn('ou.business_id', $table.'.business_id')->whereColumn('ou.'.$column, $table.'.id')->whereIn('ow.posting_status', ['posted', 'not_posted'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_workflow_corrections as fc')->join('finance_workflows as cw', 'cw.id', '=', 'fc.workflow_id')->whereColumn('fc.original_workflow_id', 'ou.workflow_id')->where('cw.posting_status', 'posted'))->selectRaw('COALESCE(SUM(ou.amount),0)');

        return $model::query()->where($table.'.business_id', $business->id)
            ->where($table.'.posting_status', 'posted')
            ->whereRaw('ROUND('.$table.'.'.$total.' - ('.$adjustments->toSql().') - ('.$payments->toSql().') - ('.$advances->toSql().') - ('.$tradeCredits->toSql().') - ('.$tradeUses->toSql().') - ('.$openingUses->toSql().') + ('.$returns->toSql().') + ('.$advanceReturns->toSql().'), 2) > 0',
                [...$adjustments->getBindings(), ...$payments->getBindings(), ...$advances->getBindings(), ...$tradeCredits->getBindings(), ...$tradeUses->getBindings(), ...$openingUses->getBindings(), ...$returns->getBindings(), ...$advanceReturns->getBindings()]);
    }

    public function supplierBill(Business $business, PurchaseOrder $purchase, array $data, int $actor): FinanceSupplierBill
    {
        return DB::transaction(function () use ($business, $purchase, $data, $actor) {
            $purchase = PurchaseOrder::query()->where('business_id', $business->id)
                ->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->market_trip_id) {
                abort_if(MarketAccountability::query()->where('business_id', $business->id)->where('market_trip_id', $purchase->market_trip_id)->where('status', 'closed')->exists(), 422, 'Reopen the market accountability before adding another source bill.');
            }
            abort_unless(in_array($purchase->status, ['part_received', 'received'], true), 422,
                'Receive accepted produce before preparing a supplier bill.');
            abort_unless(! $purchase->receipts()->where('accepted_quantity', '>', 0)->doesntExist(), 422,
                'This purchase has no accepted goods to bill.');
            abort_if(FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->where('vendor_invoice_reference', $data['vendor_invoice_reference'])->exists(), 422, 'This supplier invoice reference has already been recorded for this purchase.');
            $math = app(FinanceMath::class);
            $receipts = $purchase->receipts()->where('accepted_quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
            $available = [];
            $totalAvailable = BigDecimal::zero();
            $legacy = FinanceSupplierBill::query()->where('business_id', $business->id)->where('purchase_order_id', $purchase->id)->whereNull('receipt_snapshot')->get();
            $legacyQuantity = BigDecimal::zero();
            foreach ($legacy as $priorBill) {
                $legacyQuantity = $legacyQuantity->plus((string) $priorBill->accepted_quantity);
            }
            foreach ($receipts as $receipt) {
                $quantity = BigDecimal::of((string) $receipt->accepted_quantity);
                if ($legacyQuantity->isGreaterThan(0)) {
                    $used = BigDecimal::min($quantity, $legacyQuantity);
                    $quantity = $quantity->minus($used);
                    $legacyQuantity = $legacyQuantity->minus($used);
                }
                foreach (FinanceBillReceipt::query()->where('business_id', $business->id)->where('goods_receipt_id', $receipt->id)->pluck('quantity') as $billed) {
                    $quantity = $quantity->minus((string) $billed);
                }
                $available[$receipt->id] = $quantity;
                $totalAvailable = $totalAvailable->plus($quantity);
            }
            $accepted = BigDecimal::of((string) ($data['accepted_quantity'] ?? $totalAvailable));
            abort_unless($accepted->isGreaterThan(0) && ! $accepted->isGreaterThan($totalAvailable), 422, 'The bill exceeds accepted, unbilled produce. Pending bills already reserve their quantity.');
            $remaining = $accepted;
            $allocations = [];
            foreach ($available as $receiptId => $quantity) {
                $used = BigDecimal::min($quantity, $remaining);
                if ($used->isGreaterThan(0)) {
                    $allocations[] = ['goods_receipt_id' => $receiptId, 'quantity' => (string) $used];
                }
                $remaining = $remaining->minus($used);
            }
            $total = $math->amount($accepted, (string) $purchase->unit_price);

            $terms = $purchase->supplier_id ? app(FinanceTradeControls::class)->terms($business, 'supplier', $purchase->supplier_id, $purchase->currency, $data['vendor_invoice_date']) : null;
            $due = $data['due_on'] ?? ($terms ? Carbon::parse($data['vendor_invoice_date'])->addDays($terms->payment_days)->toDateString() : null);
            $bill = FinanceSupplierBill::create([
                'business_id' => $business->id, 'purchase_order_id' => $purchase->id,
                'reference' => 'BILL-'.Str::upper((string) Str::ulid()),
                'vendor_invoice_reference' => $data['vendor_invoice_reference'],
                'vendor_invoice_date' => $data['vendor_invoice_date'], 'due_on' => $due,
                'accepted_quantity' => (string) $accepted, 'total' => (string) $total,
                'currency' => $purchase->currency, 'notes' => $data['notes'] ?? null,
                'created_by' => $actor, 'posting_status' => 'not_posted',
                'receipt_snapshot' => ['partner_terms_id' => $terms?->id, 'unit_price' => (string) $purchase->unit_price, 'allocations' => $allocations],
            ]);
            foreach ($allocations as $allocation) {
                FinanceBillReceipt::create([...$allocation, 'business_id' => $business->id, 'supplier_bill_id' => $bill->id]);
            }

            return $bill;
        }, 3);
    }

    public function outstanding(CommercialInvoice|FinanceSupplierBill $source, bool $includePending = true, ?int $exceptWorkflow = null): BigDecimal
    {
        if ($source instanceof CommercialInvoice && $source->posting_status === 'credited') {
            return BigDecimal::zero();
        }
        $base = BigDecimal::of((string) ($source instanceof CommercialInvoice
            ? $source->grand_total : $source->total));
        $relation = $source instanceof CommercialInvoice ? 'commercial_invoice_id' : 'supplier_bill_id';
        $adjustment = FinanceBalanceAdjustment::query()
            ->where('business_id', $source->business_id)
            ->where($relation, $source->id)
            ->whereIn('posting_status', $includePending ? ['not_posted', 'posted'] : ['posted'])
            ->first();
        if ($adjustment) {
            $base = $base->minus((string) $adjustment->amount);
        }
        $statuses = $includePending ? ['pending', 'posted'] : ['posted'];
        $allocated = app(FinanceOpeningItems::class)->usedByTarget($source->business_id, $relation, $source->id, $includePending, $exceptWorkflow)['amount'];
        foreach (FinancePayment::query()->where('business_id', $source->business_id)
            ->where($relation, $source->id)->whereIn('status', $statuses)
            ->get(['id', 'amount', 'status']) as $payment) {
            $allocated = $allocated->plus((string) $payment->amount);
            if ($payment->status === 'posted') {
                foreach (FinancePartialBankReturn::query()->where('business_id', $source->business_id)
                    ->where('source_type', 'payment')->where('source_id', $payment->id)
                    ->where('posting_status', 'posted')->pluck('amount') as $returned) {
                    $allocated = $allocated->minus((string) $returned);
                }
            }
        }

        foreach (FinanceOperation::query()->where('business_id', $source->business_id)->where($relation, $source->id)
            ->whereIn('posting_status', $includePending ? ['not_posted', 'posted'] : ['posted'])->get() as $advance) {
            $allocated = $allocated->plus((string) $advance->amount)->minus(app(FinanceMoneyCorrections::class)->corrected($advance));
        }

        foreach (FinanceTradeCredit::query()->where('business_id', $source->business_id)->where($relation, $source->id)->when($exceptWorkflow, fn ($q) => $q->where('workflow_id', '!=', $exceptWorkflow))->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', $includePending ? ['posted', 'not_posted'] : ['posted']))->pluck('applied_amount') as $credit) {
            $allocated = $allocated->plus((string) $credit);
        }
        foreach (DB::table('finance_trade_credit_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $source->business_id)->where('w.business_id', $source->business_id)->where('u.'.$relation, $source->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_workflow_corrections as fc')->join('finance_workflows as cw', 'cw.id', '=', 'fc.workflow_id')->whereColumn('fc.business_id', 'u.business_id')->whereColumn('fc.original_workflow_id', 'u.workflow_id')->where('cw.posting_status', 'posted'))->when($exceptWorkflow, fn ($q) => $q->where('u.workflow_id', '!=', $exceptWorkflow))->whereIn('w.posting_status', $includePending ? ['posted', 'not_posted'] : ['posted'])->pluck('u.amount') as $credit) {
            $allocated = $allocated->plus((string) $credit);
        }

        return $base->minus($allocated);
    }

    public function payment(Business $business, array $data, int $actor): FinancePayment
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $existing = FinancePayment::query()->where('business_id', $business->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $key = $existing->direction === 'in' ? 'commercial_invoice_id' : 'supplier_bill_id';
                abort_unless($existing->direction === $data['direction'] && (int) ($data[$key] ?? 0) === $existing->{$key} && BigDecimal::of((string) $existing->amount)->isEqualTo((string) $data['amount']) && $existing->payment_on->toDateString() === $data['payment_on'] && $existing->external_reference === $data['external_reference'] && (string) $existing->notes === (string) ($data['notes'] ?? ''), 422, 'This payment request key already has different source, amount, date or evidence.');
                $account = app(FinanceTreasury::class)->moneyAccount($business, [...$data, 'currency' => $existing->currency, 'account_key' => $data['cash_account_key']], '', true);
                abort_unless($account->id === $existing->cash_account_id, 422, 'This payment request already selects another money account.');

                return $existing;
            }
            $type = $data['direction'];
            abort_unless(in_array($type, ['in', 'out'], true), 422);
            $math = app(FinanceMath::class);
            $amount = $math->money($data['amount']);
            if ($amount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['amount' => 'Payment amount must be positive.']);
            }
            if ($type === 'in') {
                $document = CommercialInvoice::query()->where('business_id', $business->id)
                    ->lockForUpdate()->findOrFail($data['commercial_invoice_id']);
                abort_unless($document->posting_status === 'posted', 422,
                    'The commercial invoice must be approved and posted before recording receipts.');
                abort_unless(! FinanceCreditNote::query()
                    ->where('business_id', $business->id)
                    ->where('commercial_invoice_id', $document->id)->exists(), 422,
                    'An invoice credit is under review or already posted. Do not allocate further payments.');
            } else {
                $document = FinanceSupplierBill::query()->where('business_id', $business->id)
                    ->lockForUpdate()->findOrFail($data['supplier_bill_id']);
                abort_unless($document->posting_status === 'posted', 422,
                    'The supplier bill must be approved and posted before making payments.');
            }
            app(FinanceTradeCredits::class)->assertNoPending($business->id, $type === 'in' ? 'commercial_invoice_id' : 'supplier_bill_id', $document->id);
            if (FinancePartialBankReturn::query()
                ->where('business_id', $business->id)
                ->where('source_type', 'payment')
                ->whereIn('source_id', FinancePayment::query()->where('business_id', $business->id)
                    ->where($type === 'in' ? 'commercial_invoice_id' : 'supplier_bill_id', $document->id)
                    ->select('id'))
                ->where('posting_status', 'not_posted')->exists()) {
                throw ValidationException::withMessages(['document' => 'A bank return on this document is awaiting finance posting. Complete it before recording new payments.']);
            }
            if (FinanceBalanceAdjustment::query()
                ->where('business_id', $business->id)
                ->where($type === 'in' ? 'commercial_invoice_id' : 'supplier_bill_id', $document->id)
                ->where('posting_status', 'not_posted')->exists()) {
                throw ValidationException::withMessages(['document' => 'Finish the pending balance-concession approval before recording a payment for this document.']);
            }
            $remaining = $this->outstanding($document);
            if ($amount->isGreaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'amount' => 'This payment exceeds the remaining document amount, including pending allocations.',
                ]);
            }
            $account = app(FinanceTreasury::class)->moneyAccount($business, [...$data, 'currency' => $document->currency, 'account_key' => $data['cash_account_key']], '', true);

            return FinancePayment::create([
                'business_id' => $business->id, 'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
                'direction' => $type, 'commercial_invoice_id' => $type === 'in' ? $document->id : null,
                'supplier_bill_id' => $type === 'out' ? $document->id : null,
                'cash_account_id' => $account->id, 'currency' => $document->currency,
                'amount' => (string) $amount, 'payment_on' => $data['payment_on'],
                'external_reference' => $data['external_reference'],
                'status' => 'pending', 'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'], 'created_by' => $actor,
            ]);
        }, 3);
    }

    public function shipmentCost(Business $business, array $data, int $actor): FinanceShipmentCost
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $proof = $data;
            unset($proof['idempotency_key']);
            $proof['amount'] = (string) app(FinanceMath::class)->money($data['amount']);
            ksort($proof);
            $hash = app(FinanceWorkflows::class)->hash($proof);
            $key = $data['idempotency_key'] ?? $hash;
            if ($old = FinanceShipmentCost::query()->where('business_id', $business->id)->where('idempotency_key', $key)->first()) {
                abort_unless(hash_equals($old->payload_hash, $hash), 422, 'This shipment cost voucher key already has different amount, date, source or money details.');

                return $old;
            }
            $shipment = ExportShipment::query()->where('business_id', $business->id)
                ->findOrFail($data['shipment_id']);
            abort_unless($shipment->status !== 'cancelled', 422,
                'Cancelled shipments cannot accept newly recorded costs.');
            $amount = app(FinanceMath::class)->money($data['amount']);
            abort_unless($amount->isGreaterThan(0), 422, 'Cost amount must be positive.');
            $account = app(FinanceTreasury::class)->moneyAccount($business, [...$data, 'currency' => strtoupper($data['currency']), 'account_key' => $data['cash_account_key']], '', true);

            if (! empty($data['transport_leg_id'])) {
                TransportLeg::query()->where('business_id', $business->id)->where('shipment_id', $shipment->id)->findOrFail($data['transport_leg_id']);
            }

            return FinanceShipmentCost::create(['idempotency_key' => $key, 'payload_hash' => $hash,
                'business_id' => $business->id, 'reference' => 'COST-'.Str::upper((string) Str::ulid()),
                'shipment_id' => $shipment->id, 'transport_leg_id' => $data['transport_leg_id'] ?? null, 'category' => $data['category'],
                'description' => $data['description'], 'currency' => strtoupper($data['currency']),
                'amount' => (string) $amount, 'cash_account_id' => $account->id,
                'paid_on' => $data['paid_on'], 'external_reference' => $data['external_reference'],
                'status' => 'pending', 'created_by' => $actor,
            ]);
        });
    }
}
