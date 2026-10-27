<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceApprovalEvent;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceBillReceipt;
use App\Models\FinanceCreditNote;
use App\Models\FinanceForeignBankOpening;
use App\Models\FinanceFxRevaluation;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceOperation;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePayment;
use App\Models\FinancePayrollReversal;
use App\Models\FinancePayrollRun;
use App\Models\FinancePayrollSettlement;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTransactionReversal;
use App\Models\FinanceWorkflow;
use App\Models\GoodsReceipt;
use App\Models\StockAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePosting
{
    private function source(int $businessId, string $type, int $id): CommercialInvoice|FinanceSupplierBill|FinancePayment|FinanceShipmentCost|FinanceInventoryRelief|FinanceCreditNote|FinanceOpeningBalance|FinanceBalanceAdjustment|FinancePayrollRun|FinancePayrollSettlement|FinancePayrollReversal|FinanceTransactionReversal|FinanceForeignBankOpening|FinanceFxRevaluation|FinancePartialBankReturn|FinanceMonetaryRevaluation|FinanceOperation|FinanceRegisterEntry|StockAdjustment|FinanceWorkflow
    {
        $model = match ($type) {
            'finance_workflow' => FinanceWorkflow::class,
            'stock_adjustment' => StockAdjustment::class,
            'register_entry' => FinanceRegisterEntry::class,
            'money_operation' => FinanceOperation::class,
            'commercial_invoice' => CommercialInvoice::class,
            'supplier_bill' => FinanceSupplierBill::class,
            'payment' => FinancePayment::class,
            'shipment_cost' => FinanceShipmentCost::class,
            'inventory_relief' => FinanceInventoryRelief::class,
            'credit_note' => FinanceCreditNote::class,
            'opening_balance' => FinanceOpeningBalance::class,
            'balance_adjustment' => FinanceBalanceAdjustment::class,
            'payroll_run' => FinancePayrollRun::class,
            'payroll_settlement' => FinancePayrollSettlement::class,
            'payroll_reversal' => FinancePayrollReversal::class,
            'transaction_reversal' => FinanceTransactionReversal::class,
            'foreign_bank_opening' => FinanceForeignBankOpening::class,
            'fx_revaluation' => FinanceFxRevaluation::class,
            'partial_bank_return' => FinancePartialBankReturn::class,
            'monetary_revaluation' => FinanceMonetaryRevaluation::class,
            default => abort(422, 'This financial document type is not supported.'),
        };

        return $model::query()->where('business_id', $businessId)->lockForUpdate()->findOrFail($id);
    }

    private function state(CommercialInvoice|FinanceSupplierBill|FinancePayment|FinanceShipmentCost|FinanceInventoryRelief|FinanceCreditNote|FinanceOpeningBalance|FinanceBalanceAdjustment|FinancePayrollRun|FinancePayrollSettlement|FinancePayrollReversal|FinanceTransactionReversal|FinanceForeignBankOpening|FinanceFxRevaluation|FinancePartialBankReturn|FinanceMonetaryRevaluation|FinanceOperation|FinanceRegisterEntry|StockAdjustment|FinanceWorkflow $record): string
    {
        return $record instanceof CommercialInvoice || $record instanceof FinanceSupplierBill
            || $record instanceof FinanceInventoryRelief || $record instanceof FinanceCreditNote
            || $record instanceof FinanceOpeningBalance || $record instanceof FinanceBalanceAdjustment
            || $record instanceof FinancePayrollRun || $record instanceof FinancePayrollReversal
            || $record instanceof FinanceTransactionReversal || $record instanceof FinanceForeignBankOpening
            || $record instanceof FinanceFxRevaluation
            || $record instanceof FinancePartialBankReturn
            || $record instanceof FinanceMonetaryRevaluation || $record instanceof FinanceOperation || $record instanceof FinanceRegisterEntry || $record instanceof StockAdjustment || $record instanceof FinanceWorkflow
            ? $record->posting_status : $record->status;
    }

    private function event(FinancePostingRequest $request, int $actor, string $action, ?string $reason = null): void
    {
        FinanceApprovalEvent::create([
            'business_id' => $request->business_id, 'posting_request_id' => $request->id,
            'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    public function request(Business $business, string $type, int $sourceId, array $data, int $userId): FinancePostingRequest
    {
        return DB::transaction(function () use ($business, $type, $sourceId, $data, $userId) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(FinancePeriodControls::class)->assertWritable($business, $data['posting_on']);
            $source = $this->source($business->id, $type, $sourceId);
            abort_unless($this->state($source) === (in_array($type, ['payment', 'shipment_cost', 'payroll_settlement'], true) ? 'pending' : 'not_posted'),
                422, 'This financial document cannot be submitted for posting.');
            if ($type === 'commercial_invoice') {
                $shipment = $source->shipment;
                abort_unless(in_array($shipment->status, ['dispatched', 'delivered'], true), 422,
                    'Record shipment dispatch before submitting revenue recognition for review.');
            }
            $currency = strtoupper($source->currency);
            if ($type === 'inventory_relief') {
                app(FinanceInventoryValuation::class)->validateBeforePost($source);
            }
            if ($type === 'finance_workflow') {
                abort_unless($data['posting_on'] === $source->event_on->toDateString() && ($data['exchange_rate_id'] ?? null) === $source->exchange_rate_id, 422, 'Use the prepared workflow date and rate.');
                app(FinanceWorkflows::class)->validate($source);
                $fx = (string) $source->rate_to_base;
            } elseif ($type === 'stock_adjustment') {
                abort_unless(($data['exchange_rate_id'] ?? null) === null && $source->currency === $business->base_currency, 422, 'Stock count adjustments use the frozen base-currency cost.');
                app(StockExceptions::class)->lines($source);
                $fx = '1';
            } elseif ($type === 'register_entry') {
                abort_unless($data['posting_on'] === $source->event_on->toDateString() && ($data['exchange_rate_id'] ?? null) === $source->exchange_rate_id, 422, 'Use the register transaction date and frozen FX reference.');
                app(FinanceRegisterPostings::class)->lines($source);
                $fx = (string) $source->rate_to_base;
            } elseif ($type === 'money_operation') {
                abort_unless($data['posting_on'] === $source->event_on->toDateString() && ($data['exchange_rate_id'] ?? null) === $source->exchange_rate_id, 422, 'Use the frozen rate selected when this money record was prepared.');
                app(FinanceOperations::class)->lines($source);
                $fx = (string) $source->rate_to_base;
            } elseif ($type === 'partial_bank_return') {
                if (($data['exchange_rate_id'] ?? null) !== null
                    || $data['posting_on'] !== $source->data_snapshot['return_date']) {
                    throw ValidationException::withMessages(['posting_on' => 'Post this return on its actual bank event date, in an open financial period, using the source journal FX rate.']);
                }
                app(FinancePartialBankReturns::class)->lines($source);
                $original = FinanceJournal::query()->where('business_id', $business->id)
                    ->findOrFail($source->original_journal_id);
                $fx = (string) $original->rate_to_base;
            } elseif ($type === 'monetary_revaluation') {
                if (($data['exchange_rate_id'] ?? null) !== $source->exchange_rate_id
                    || $data['posting_on'] !== $source->valued_on->toDateString()) {
                    throw ValidationException::withMessages(['posting_on' => 'The monetary-item revaluation must be posted on its original approved valuation date with its frozen rate.']);
                }
                app(FinanceMonetaryRevaluations::class)->lines($source);
                $fx = app(FinanceRates::class)->rate($business, $currency,
                    $source->exchange_rate_id, $data['posting_on']);
            } elseif ($type === 'fx_revaluation') {
                if (($data['exchange_rate_id'] ?? null) !== $source->exchange_rate_id
                    || $data['posting_on'] !== $source->valued_on->toDateString()) {
                    throw ValidationException::withMessages(['posting_on' => 'Revaluation must be posted on its exact valuation date with the locked approved rate.']);
                }
                app(FinanceFxRevaluations::class)->lines($source);
                $fx = app(FinanceRates::class)->rate($business, $currency,
                    $source->exchange_rate_id, $data['posting_on']);
            } elseif ($type === 'transaction_reversal') {
                if ($source->return_bank_line_id !== null
                    && $data['posting_on'] !== $source->original_snapshot['bank_return_evidence']['return_transaction_on']) {
                    throw ValidationException::withMessages(['posting_on' => 'A bank-matched return must be posted on the actual returned bank transaction date. Closed periods remain locked.']);
                }
                if (($data['exchange_rate_id'] ?? null) !== null) {
                    abort(422,
                        'A payment reversal must reuse its original journal FX rate.');
                }
                $original = app(FinanceTransactionCorrections::class)->original($source);
                app(FinanceTransactionCorrections::class)->lines($source);
                $fx = (string) $original->rate_to_base;
            } elseif ($type === 'foreign_bank_opening') {
                if (($data['exchange_rate_id'] ?? null) !== $source->exchange_rate_id) {
                    throw ValidationException::withMessages(['exchange_rate_id' => 'Select the exact historical exchange rate frozen in this bank opening.']);
                }
                app(FinanceForeignBankOpenings::class)->lines($source);
                $fx = app(FinanceRates::class)->rate($business, $currency,
                    $source->exchange_rate_id, $data['posting_on']);
            } elseif (in_array($type, ['payroll_settlement', 'payroll_reversal'], true)) {
                if (($data['exchange_rate_id'] ?? null) !== null || $currency !== $business->base_currency) {
                    abort(422,
                        'Payroll settlements and corrections require base-currency accounting.');
                }
                if ($type === 'payroll_settlement') {
                    app(FinancePayrollSettlements::class)->lines($source);
                } else {
                    app(FinancePayrollCorrections::class)->lines($source);
                }
                $fx = '1';
            } elseif ($type === 'credit_note' || $type === 'balance_adjustment') {
                if (($data['exchange_rate_id'] ?? null) !== null) {
                    abort(422,
                        'Credits and balance concessions use the original posting exchange rate.');
                }
                if ($type === 'credit_note') {
                    $original = app(FinanceCreditNotes::class)->assertEligible($source);
                } else {
                    app(FinanceBalanceAdjustments::class)->lines($source);
                    $original = FinanceJournal::query()->where('business_id', $business->id)
                        ->findOrFail($source->data_snapshot['original_journal_id']);
                }
                $fx = (string) $original->rate_to_base;
            } elseif (in_array($type, ['opening_balance', 'payroll_run'], true)) {
                if (($data['exchange_rate_id'] ?? null) !== null || $currency !== $business->base_currency) {
                    abort(422, 'Opening balances and staff payroll must be entered in the company base currency.');
                }
                if ($type === 'opening_balance') {
                    app(FinanceOpeningBalances::class)->lines($source);
                } else {
                    app(FinancePayroll::class)->lines($source);
                }
                $fx = '1';
            } else {
                $fx = app(FinanceRates::class)->rate($business, $currency,
                    $data['exchange_rate_id'] ?? null, $data['posting_on']);
            }
            $date = match ($type) {
                'finance_workflow' => $source->event_on->toDateString(),
                'stock_adjustment' => $source->event_on->toDateString(),
                'register_entry' => $source->event_on->toDateString(),
                'money_operation' => $source->event_on->toDateString(),
                'commercial_invoice' => $source->issued_at->copy()->timezone($business->timezone)->toDateString(),
                'supplier_bill' => $source->vendor_invoice_date->toDateString(),
                'payment' => $source->payment_on->toDateString(),
                'shipment_cost' => $source->paid_on->toDateString(),
                'inventory_relief' => $source->created_at->toDateString(),
                'credit_note' => $source->created_at->toDateString(),
                'opening_balance' => $source->effective_on->toDateString(),
                'balance_adjustment' => $source->created_at->toDateString(),
                'payroll_run' => $source->period_to->toDateString(),
                'payroll_settlement' => $source->paid_on->toDateString(),
                'payroll_reversal' => $source->created_at->toDateString(),
                'transaction_reversal' => $source->return_bank_line_id !== null
                    ? $source->original_snapshot['bank_return_evidence']['return_transaction_on']
                    : $source->created_at->toDateString(),
                'foreign_bank_opening' => $source->effective_on->toDateString(),
                'fx_revaluation' => $source->valued_on->toDateString(),
                'monetary_revaluation' => $source->valued_on->toDateString(),
                'partial_bank_return' => $source->data_snapshot['return_date'],
            };
            if ($data['posting_on'] < $date) {
                throw ValidationException::withMessages([
                    'posting_on' => 'The journal date cannot precede the documented business event.',
                ]);
            }
            $record = FinancePostingRequest::query()->where('business_id', $business->id)
                ->where('source_type', $type)->where('source_id', $sourceId)
                ->lockForUpdate()->first();
            if ($record && $record->status !== 'rejected') {
                abort(422, 'This document already has a pending, approved or posted review.');
            }
            $entry = [
                'posting_on' => $data['posting_on'], 'exchange_rate_id' => $data['exchange_rate_id'] ?? null,
                'cash_carrying_snapshot' => null, 'basis_notes' => $data['basis_notes'], 'requested_by' => $userId,
                'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'posted_by' => null, 'posted_at' => null,
            ];
            if ($record) {
                $record->update($entry);
            } else {
                $record = FinancePostingRequest::create([
                    ...$entry, 'business_id' => $business->id, 'source_type' => $type, 'source_id' => $sourceId,
                ]);
            }
            $this->event($record, $userId, 'submitted',
                'Currency '.$currency.'; rate to base '.$fx.'; '.$data['basis_notes']);

            return $record;
        }, 3);
    }

    private function cashSnapshot(Business $business, FinancePostingRequest $request, mixed $source): ?array
    {
        if ($source instanceof FinanceWorkflow) {
            $sources = [];
            foreach ($source->legs()->get() as $leg) {
                $sources[] = ['type' => 'workflow_leg', 'id' => $leg->id, 'currency' => $leg->currency, 'date' => $leg->event_on->toDateString(), 'lines' => $leg->lines];
            }

            return app(FinanceCashCarrying::class)->snapshot($business, $sources);
        }
        if ($source->currency === $business->base_currency || ! in_array($request->source_type, ['money_operation', 'register_entry', 'payment', 'shipment_cost', 'transaction_reversal', 'partial_bank_return'], true)) {
            return null;
        }
        $rate = in_array($request->source_type, ['transaction_reversal', 'partial_bank_return'], true) ? null : ($source->rate_to_base ?? app(FinanceRates::class)->rate($business, $source->currency, $request->exchange_rate_id, $request->posting_on->toDateString()));
        $lines = match ($request->source_type) {
            'money_operation' => app(FinanceOperations::class)->lines($source),'register_entry' => app(FinanceRegisterPostings::class)->lines($source),'payment' => $this->paymentLines($business, $source, (string) $rate),'shipment_cost' => $this->costLines($source, (string) $rate),'transaction_reversal' => app(FinanceTransactionCorrections::class)->lines($source),'partial_bank_return' => app(FinancePartialBankReturns::class)->lines($source),default => null
        };

        return $lines ? app(FinanceCashCarrying::class)->snapshot($business, [['type' => $request->source_type, 'id' => $source->id, 'currency' => $source->currency, 'date' => $request->posting_on->toDateString(), 'lines' => $lines]]) : null;
    }

    public function review(FinancePostingRequest $request, bool $approve, int $actor, string $notes): FinancePostingRequest
    {
        return DB::transaction(function () use ($request, $approve, $actor, $notes) {
            $request = FinancePostingRequest::query()->where('business_id', $request->business_id)
                ->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'pending', 422, 'Review is no longer pending.');
            abort_unless($request->requested_by !== $actor, 422,
                'A different authorised person must review this posting request.');
            $source = $this->source($request->business_id, $request->source_type, $request->source_id);
            if ($source instanceof FinanceWorkflow || $source instanceof StockAdjustment || $source instanceof FinanceRegisterEntry || $source instanceof FinanceOperation || ($source instanceof FinancePayrollRun && in_array($source->data_snapshot['method'] ?? null, ['approved_time_and_agreed_rate', 'approved_inputs_and_statutory_policy'], true))) {
                abort_unless($source->created_by !== $actor, 422, 'The person preparing this money or time-payroll record cannot review it.');
            }
            if ($approve && $source instanceof FinanceWorkflow) {
                app(FinanceWorkflows::class)->validate($source);
            }
            if ($approve && $request->source_type === 'stock_adjustment') {
                app(StockExceptions::class)->lines($source);
            }
            if ($approve && $request->source_type === 'register_entry') {
                app(FinanceRegisterPostings::class)->lines($source);
            }
            if ($approve && $request->source_type === 'money_operation') {
                app(FinanceOperations::class)->lines($source);
            }
            if ($request->source_type === 'credit_note') {
                app(FinanceCreditNotes::class)->assertEligible($source);
            }
            if ($request->source_type === 'inventory_relief') {
                app(FinanceInventoryValuation::class)->validateBeforePost($source);
            }
            if ($request->source_type === 'opening_balance') {
                app(FinanceOpeningBalances::class)->lines($source);
            }
            if ($request->source_type === 'balance_adjustment') {
                app(FinanceBalanceAdjustments::class)->lines($source);
            }
            if ($request->source_type === 'payroll_run') {
                app(FinancePayroll::class)->lines($source);
            }
            if ($request->source_type === 'payroll_settlement') {
                app(FinancePayrollSettlements::class)->lines($source);
            }
            if ($request->source_type === 'payroll_reversal') {
                app(FinancePayrollCorrections::class)->lines($source);
            }
            if ($request->source_type === 'transaction_reversal') {
                app(FinanceTransactionCorrections::class)->lines($source);
            }
            if ($request->source_type === 'foreign_bank_opening') {
                app(FinanceForeignBankOpenings::class)->lines($source);
            }
            if ($request->source_type === 'fx_revaluation') {
                app(FinanceFxRevaluations::class)->lines($source);
            }
            if ($request->source_type === 'monetary_revaluation') {
                app(FinanceMonetaryRevaluations::class)->lines($source);
            }
            if ($request->source_type === 'partial_bank_return') {
                app(FinancePartialBankReturns::class)->lines($source);
            }
            abort_unless($this->state($source) === (
                in_array($request->source_type, ['payment', 'shipment_cost', 'payroll_settlement'], true) ? 'pending' : 'not_posted'),
                422, 'The source record has changed.');
            $cashSnapshot = $approve ? $this->cashSnapshot(Business::findOrFail($request->business_id), $request, $source) : null;
            $request->update([
                'cash_carrying_snapshot' => $cashSnapshot, 'status' => $approve ? 'approved' : 'rejected',
                'approved_by' => $approve ? $actor : null,
                'approved_at' => $approve ? now() : null,
            ]);
            if (! $approve && $source instanceof FinanceWorkflow) {
                app(FinanceWorkflows::class)->reject($source);
            }
            if (! $approve && ($source instanceof FinanceWorkflow || $source instanceof FinanceOperation || $source instanceof FinanceRegisterEntry || $source instanceof StockAdjustment)) {
                $source->update(['posting_status' => 'rejected']);
            }
            if (! $approve && ($source instanceof FinancePayment || $source instanceof FinanceShipmentCost
                || $source instanceof FinancePayrollSettlement)) {
                $source->update(['status' => 'rejected']);
            }
            $this->event($request, $actor, $approve ? 'approved' : 'rejected', $notes);

            return $request;
        }, 3);
    }

    public function refreshCashApproval(FinancePostingRequest $request, int $actor, string $reason): void
    {
        DB::transaction(function () use ($request, $actor, $reason) {
            app(OrderOperations::class)->lockForWork($request->business_id, 'finance');
            $request = FinancePostingRequest::query()->where('business_id', $request->business_id)->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'approved' && $request->approved_by === $actor && $request->cash_carrying_snapshot, 422, 'Only the approver may reopen an unposted foreign cash approval for a new balance review.');
            $source = $this->source($request->business_id, $request->source_type, $request->source_id);
            abort_unless(in_array($this->state($source), ['not_posted', 'pending'], true), 422, 'This source is already posted or cancelled.');
            $old = hash('sha256', json_encode($request->cash_carrying_snapshot, JSON_THROW_ON_ERROR));
            $request->update(['status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'cash_carrying_snapshot' => null]);
            $this->event($request, $actor, 'cash_approval_reopened', $reason.'; previous cash-basis hash '.$old);
        }, 3);
    }

    public function withdraw(FinancePostingRequest $request, int $actor, string $reason): void
    {
        DB::transaction(function () use ($request, $actor, $reason) {
            app(OrderOperations::class)->lockForWork($request->business_id, 'finance');
            $request = FinancePostingRequest::query()->where('business_id', $request->business_id)->lockForUpdate()->findOrFail($request->id);
            abort_unless(in_array($request->source_type, ['finance_workflow', 'register_entry', 'stock_adjustment', 'money_operation'], true) && in_array($request->status, ['pending', 'approved'], true), 422, 'Only an unposted supported financial review can be withdrawn.');
            abort_unless($request->status === 'approved' ? $request->approved_by === $actor : $request->requested_by === $actor, 422, 'The requester may withdraw a pending request; only its approver may withdraw an approval.');
            $source = $this->source($request->business_id, $request->source_type, $request->source_id);
            abort_unless($this->state($source) === 'not_posted' && ! FinanceJournal::query()->where('business_id', $request->business_id)->where('source_type', $request->source_type)->where('source_id', $source->id)->exists(), 422, 'A posted source cannot be withdrawn. Use a controlled correction.');
            $request->update(['status' => 'rejected']);
            $source->update(['posting_status' => 'rejected']);
            if ($source instanceof FinanceWorkflow) {
                app(FinanceWorkflows::class)->reject($source);
            }
            $this->event($request, $actor, 'withdrawn', $reason);
        }, 3);
    }

    public function post(FinancePostingRequest $request, int $actor): FinanceJournal
    {
        return DB::transaction(function () use ($request, $actor) {
            Business::query()->whereKey($request->business_id)->lockForUpdate()->firstOrFail();
            $request = FinancePostingRequest::query()->where('business_id', $request->business_id)
                ->lockForUpdate()->findOrFail($request->id);
            app(FinancePeriodControls::class)->assertWritable(
                Business::query()->findOrFail($request->business_id),
                $request->posting_on->toDateString());
            abort_unless($request->status === 'approved' && $request->approved_by !== null, 422,
                'An independent approval is required before posting.');
            abort_unless($request->approved_by !== $actor, 422,
                'The reviewer cannot also post this journal.');
            $business = Business::query()->findOrFail($request->business_id);
            $source = $this->source($business->id, $request->source_type, $request->source_id);
            abort_unless($this->state($source) === (
                in_array($request->source_type, ['payment', 'shipment_cost', 'payroll_settlement'], true) ? 'pending' : 'not_posted'),
                422, 'The financial document is no longer pending.');
            if ($request->source_type === 'commercial_invoice' && ! in_array(
                $source->shipment->status, ['dispatched', 'delivered'], true)) {
                abort(422, 'Shipment dispatch status has changed. Re-review recognition evidence.');
            }
            if ($source instanceof FinanceWorkflow) {
                abort_unless($request->posting_on->toDateString() === $source->event_on->toDateString() && $request->exchange_rate_id === $source->exchange_rate_id, 422, 'Workflow date and rate are frozen.');
                $journal = app(FinanceWorkflows::class)->post($source, $actor, $request->approved_by, $request->cash_carrying_snapshot);
                $request->update(['status' => 'posted', 'posted_by' => $actor, 'posted_at' => now()]);
                $this->event($request, $actor, 'posted', 'Workflow '.$source->reference.'; journal '.$journal->reference);

                return $journal;
            }
            if ($request->source_type === 'stock_adjustment') {
                abort_unless($request->exchange_rate_id === null && $source->currency === $business->base_currency, 422);
                $rate = '1';
            } elseif ($request->source_type === 'register_entry') {
                abort_unless($request->posting_on->toDateString() === $source->event_on->toDateString() && $request->exchange_rate_id === $source->exchange_rate_id, 422, 'The register event date and rate are frozen.');
                $rate = (string) $source->rate_to_base;
            } elseif ($request->source_type === 'money_operation') {
                abort_unless($request->posting_on->toDateString() === $source->event_on->toDateString() && $request->exchange_rate_id === $source->exchange_rate_id, 422, 'The money record date and rate are frozen.');
                $rate = (string) $source->rate_to_base;
            } elseif ($request->source_type === 'partial_bank_return') {
                abort_unless($request->exchange_rate_id === null
                    && $request->posting_on->toDateString() === $source->data_snapshot['return_date'], 422,
                    'Partial bank returns must post on the imported bank date with original locked FX.');
                app(FinancePartialBankReturns::class)->lines($source);
                $original = FinanceJournal::query()->where('business_id', $business->id)
                    ->findOrFail($source->original_journal_id);
                $rate = (string) $original->rate_to_base;
            } elseif ($request->source_type === 'monetary_revaluation') {
                abort_unless($request->exchange_rate_id === $source->exchange_rate_id
                    && $request->posting_on->toDateString() === $source->valued_on->toDateString(), 422,
                    'Document revaluation must use the original dated, approved FX reference.');
                app(FinanceMonetaryRevaluations::class)->lines($source);
                $rate = app(FinanceRates::class)->rate($business, $source->currency,
                    $source->exchange_rate_id, $request->posting_on->toDateString());
            } elseif ($request->source_type === 'fx_revaluation') {
                abort_unless($request->exchange_rate_id === $source->exchange_rate_id
                    && $request->posting_on->toDateString() === $source->valued_on->toDateString(),
                    422, 'The revaluation cannot be posted outside its original valuation date.');
                app(FinanceFxRevaluations::class)->lines($source);
                $rate = app(FinanceRates::class)->rate($business, $source->currency,
                    $source->exchange_rate_id, $request->posting_on->toDateString());
            } elseif ($request->source_type === 'transaction_reversal') {
                if ($source->return_bank_line_id !== null) {
                    abort_unless(
                        $request->posting_on->toDateString() ===
                        $source->original_snapshot['bank_return_evidence']['return_transaction_on'], 422,
                        'Bank return must be posted on the actual transaction date.');
                }
                abort_unless($request->exchange_rate_id === null, 422,
                    'Payment reversals must reuse the exact original FX rate.');
                $original = app(FinanceTransactionCorrections::class)->original($source);
                $rate = (string) $original->rate_to_base;
            } elseif ($request->source_type === 'foreign_bank_opening') {
                abort_unless($request->exchange_rate_id === $source->exchange_rate_id, 422,
                    'The bank opening must use its prepared approved FX reference.');
                app(FinanceForeignBankOpenings::class)->lines($source);
                $rate = app(FinanceRates::class)->rate($business, $source->currency,
                    $source->exchange_rate_id, $request->posting_on->toDateString());
            } elseif (in_array($request->source_type, ['credit_note', 'balance_adjustment'], true)) {
                if ($request->source_type === 'credit_note') {
                    $original = app(FinanceCreditNotes::class)->assertEligible($source);
                } else {
                    app(FinanceBalanceAdjustments::class)->lines($source);
                    $original = FinanceJournal::query()->where('business_id', $business->id)
                        ->findOrFail($source->data_snapshot['original_journal_id']);
                }
                $rate = (string) $original->rate_to_base;
                abort_unless($request->exchange_rate_id === null, 422, 'Credit rate cannot be changed.');
            } elseif (in_array($request->source_type, ['opening_balance', 'payroll_run', 'payroll_settlement', 'payroll_reversal'], true)) {
                abort_unless($request->exchange_rate_id === null
                    && $source->currency === $business->base_currency, 422);
                $rate = '1';
            } else {
                $rate = app(FinanceRates::class)->rate($business, strtoupper($source->currency),
                    $request->exchange_rate_id, $request->posting_on->toDateString());
            }
            $lines = match ($request->source_type) {
                'stock_adjustment' => app(StockExceptions::class)->lines($source),
                'register_entry' => app(FinanceRegisterPostings::class)->lines($source),
                'money_operation' => app(FinanceOperations::class)->lines($source),
                'commercial_invoice' => $this->invoiceLines($source, $rate),
                'supplier_bill' => $this->billLines($source, $rate),
                'payment' => $this->paymentLines($business, $source, $rate),
                'shipment_cost' => $this->costLines($source, $rate),
                'inventory_relief' => $this->inventoryReliefLines($source),
                'credit_note' => app(FinanceCreditNotes::class)->lines($source),
                'opening_balance' => app(FinanceOpeningBalances::class)->lines($source),
                'balance_adjustment' => app(FinanceBalanceAdjustments::class)->lines($source),
                'payroll_run' => app(FinancePayroll::class)->lines($source),
                'payroll_settlement' => app(FinancePayrollSettlements::class)->lines($source),
                'payroll_reversal' => app(FinancePayrollCorrections::class)->lines($source),
                'transaction_reversal' => app(FinanceTransactionCorrections::class)->lines($source),
                'foreign_bank_opening' => app(FinanceForeignBankOpenings::class)->lines($source),
                'fx_revaluation' => app(FinanceFxRevaluations::class)->lines($source),
                'monetary_revaluation' => app(FinanceMonetaryRevaluations::class)->lines($source),
                'partial_bank_return' => app(FinancePartialBankReturns::class)->lines($source),
            };
            $journal = app(FinanceLedger::class)->record(
                $business, $request->source_type, $source->id, $source->currency, $rate,
                $request->posting_on->toDateString(),
                $this->description($request->source_type, $source), $actor, $request->approved_by, $lines, $request->cash_carrying_snapshot['sources'][$request->source_type.':'.$source->id] ?? null,
            );
            if ($source instanceof StockAdjustment) {
                app(StockExceptions::class)->finalize($source, $actor);
            } elseif ($source instanceof FinanceRegisterEntry) {
                app(FinanceRegisterPostings::class)->finalize($source);
            } elseif ($source instanceof FinanceOperation) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof CommercialInvoice) {
                $source->update(['posting_status' => 'posted']);
                $source->accountingHandoff()->update(['posting_status' => 'posted']);
            } elseif ($source instanceof FinanceSupplierBill) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof FinanceCreditNote) {
                app(FinanceCreditNotes::class)->finalize($source);
            } elseif ($source instanceof FinanceInventoryRelief || $source instanceof FinanceOpeningBalance
                || $source instanceof FinanceBalanceAdjustment || $source instanceof FinancePayrollRun) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof FinanceTransactionReversal) {
                app(FinanceTransactionCorrections::class)->finalize($source);
            } elseif ($source instanceof FinancePartialBankReturn
                || $source instanceof FinanceMonetaryRevaluation) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof FinanceFxRevaluation) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof FinanceForeignBankOpening) {
                $source->update(['posting_status' => 'posted', 'posted_at' => now()]);
            } elseif ($source instanceof FinancePayrollReversal) {
                app(FinancePayrollCorrections::class)->finalize($source, $actor);
            } elseif ($source instanceof FinancePayment || $source instanceof FinancePayrollSettlement) {
                $source->update(['status' => 'posted', 'posted_at' => now()]);
            } else {
                $source->update(['status' => 'posted', 'posted_at' => now()]);
            }
            $request->update(['status' => 'posted', 'posted_by' => $actor, 'posted_at' => now()]);
            $this->event($request, $actor, 'posted', 'Journal '.$journal->reference);

            return $journal;
        }, 3);
    }

    private function description(string $type, $source): string
    {
        return match ($type) {
            'stock_adjustment' => 'Approved stock count '.$source->reference,
            'register_entry' => 'Asset / borrowing transaction '.$source->reference,
            'money_operation' => 'Money operation '.$source->reference,
            'commercial_invoice' => 'Commercial invoice '.$source->reference,
            'supplier_bill' => 'Supplier bill '.$source->reference,
            'payment' => 'Payment '.$source->reference,
            'shipment_cost' => 'Shipment cost '.$source->reference,
            'inventory_relief' => 'Inventory '.($source->kind === 'cogs' ? 'cost of goods sold' : 'sorting/waste write-off').' '.$source->reference,
            'credit_note' => 'Full commercial credit '.$source->reference,
            'opening_balance' => 'Opening balances '.$source->reference,
            'balance_adjustment' => ucfirst($source->kind).' concession '.$source->reference,
            'payroll_run' => 'Accrued staff payroll '.$source->reference,
            'payroll_settlement' => ($source->kind === 'salary' ? 'Recorded wage settlement ' : 'Recorded withholding remittance ').$source->reference,
            'payroll_reversal' => 'Correction reversing payroll accrual '.$source->reference,
            'transaction_reversal' => 'Review-approved payment correction '.$source->reference,
            'foreign_bank_opening' => 'Foreign bank opening '.$source->reference,
            'fx_revaluation' => 'Non-cash foreign bank revaluation '.$source->reference,
            'monetary_revaluation' => 'Non-cash foreign receivable/payable revaluation '.$source->reference,
            'partial_bank_return' => 'Partially returned bank transaction '.$source->reference,
        };
    }

    private function invoiceLines(CommercialInvoice $invoice, string $rate): array
    {
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $total = $math->money((string) $invoice->grand_total);
        $tax = $math->money((string) $invoice->tax_amount);
        $revenue = $total->minus($tax);
        $totalBase = $math->base($total, $rate);
        $revenueBase = $math->base($revenue, $rate);
        $taxBase = $totalBase->minus($revenueBase);
        $lines = [
            $ledger->line('receivable', (string) $total, '0', (string) $totalBase, '0', 'Buyer amount due'),
            $ledger->line('export_sales', '0', (string) $revenue, '0', (string) $revenueBase, 'Recognised sales, freight and insurance'),
        ];
        if ($tax->isGreaterThan(0)) {
            $lines[] = $ledger->line('sales_tax', '0', (string) $tax, '0', (string) $taxBase,
                'Tax amount from manually configured commercial invoice');
        }

        return $lines;
    }

    private function billLines(FinanceSupplierBill $bill, string $rate): array
    {
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $amount = $math->money((string) $bill->total);
        $base = $math->base($amount, $rate);
        if ($bill->receipt_snapshot) {
            $allocated = FinanceBillReceipt::query()->where('business_id', $bill->business_id)->where('supplier_bill_id', $bill->id)->orderBy('goods_receipt_id')->lockForUpdate()->get();
            $snapshot = $bill->receipt_snapshot;
            abort_unless($allocated->count() === count($snapshot['allocations']) && $math->amount((string) $bill->accepted_quantity, $snapshot['unit_price'])->isEqualTo($amount), 422, 'Bill quantity or price has changed.');
            $remaining = $base;
            $quantity = BigDecimal::zero();
            foreach ($allocated as $i => $row) {
                $original = $snapshot['allocations'][$i];
                abort_unless($row->goods_receipt_id === $original['goods_receipt_id'] && BigDecimal::of((string) $row->quantity)->isEqualTo($original['quantity']), 422, 'Receipt cost allocation changed.');
                $receipt = GoodsReceipt::query()->where('business_id', $bill->business_id)->where('purchase_order_id', $bill->purchase_order_id)->findOrFail($row->goods_receipt_id);
                abort_unless(! BigDecimal::of((string) $row->quantity)->isGreaterThan((string) $receipt->accepted_quantity), 422);
                $cost = $i === $allocated->count() - 1 ? $remaining : $math->money($base->multipliedBy((string) $row->quantity)->dividedBy((string) $bill->accepted_quantity, 14, RoundingMode::HalfUp));
                $row->update(['base_cost' => (string) $cost]);
                $remaining = $remaining->minus($cost);
                $quantity = $quantity->plus((string) $row->quantity);
            }
            abort_unless($quantity->isEqualTo((string) $bill->accepted_quantity), 422, 'Bill receipt quantities do not balance.');
        }

        return [
            $ledger->line('inventory', (string) $amount, '0', (string) $base, '0', 'Accepted produce purchase'),
            $ledger->line('payable', '0', (string) $amount, '0', (string) $base, 'Supplier amount due'),
        ];
    }

    private function paymentLines(Business $business, FinancePayment $payment, string $rate): array
    {
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $amount = $math->money((string) $payment->amount);
        $cash = $math->base($amount, $rate);
        $original = $payment->direction === 'in' ? $payment->invoice : $payment->bill;
        if (! $original || $original->business_id !== $business->id) {
            abort(422, 'Invalid payment source.');
        }
        $journal = FinanceJournal::query()->where('business_id', $business->id)
            ->where('source_type', $payment->direction === 'in' ? 'commercial_invoice' : 'supplier_bill')
            ->where('source_id', $original->id)->firstOrFail();
        // Consume the CURRENT carrying value, not the original exchange rate.
        // This matters after an approved receivable/payable FX revaluation and
        // after partial bank returns, which reopen a portion of the balance.
        $remaining = app(FinanceDocuments::class)->outstanding($original, false);
        if ($remaining->isLessThanOrEqualTo(0) || $amount->isGreaterThan($remaining)) {
            throw ValidationException::withMessages(['amount' => 'The posted document balance has changed. Payment exceeds the remaining confirmed amount.']);
        }
        $unrealizedToReclass = BigDecimal::zero();
        if ($original->currency === $business->base_currency) {
            $settled = $amount;
        } else {
            $position = app(FinanceMonetaryRevaluations::class)->position($business,
                $payment->direction === 'in' ? 'commercial_invoice' : 'supplier_bill', $original->id);
            abort_unless($remaining->isEqualTo($position['foreign_balance']), 422,
                'Foreign document units and posted ledger carrying amount disagree.');
            if ($payment->payment_on->toDateString() < $position['latest_journal_on']) {
                throw ValidationException::withMessages(['payment_on' => 'A newly posted payment cannot precede the latest journal that valued this document.']);
            }
            $carrying = BigDecimal::of($position['previous_base']);
            $unrealized = BigDecimal::of($position['unrealized_open']);
            $settled = $amount->isEqualTo($remaining) ? $carrying
                : $math->money($carrying->multipliedBy($amount)
                    ->dividedBy($remaining, 14, RoundingMode::HalfUp));
            $unrealizedToReclass = $amount->isEqualTo($remaining) ? $unrealized
                : $math->money($unrealized->multipliedBy($amount)
                    ->dividedBy($remaining, 14, RoundingMode::HalfUp));
        }
        $payment->update(['settled_base_amount' => (string) $settled]);
        $cashAccount = $payment->cashAccount;
        abort_unless($cashAccount?->business_id === $business->id, 422);
        app(FinanceBankProfiles::class)->assertPaymentAccount($cashAccount, $payment->currency);
        if ($payment->direction === 'in') {
            $lines = [
                $ledger->line($cashAccount->system_key, (string) $amount, '0', (string) $cash, '0', 'Manually recorded customer receipt'),
                $ledger->line('receivable', '0', (string) $amount, '0', (string) $settled, 'Receivable allocation'),
            ];
            $difference = $cash->minus($settled);
        } else {
            $lines = [
                $ledger->line('payable', (string) $amount, '0', (string) $settled, '0', 'Supplier balance settled'),
                $ledger->line($cashAccount->system_key, '0', (string) $amount, '0', (string) $cash, 'Manual supplier disbursement'),
            ];
            $difference = $settled->minus($cash);
        }
        if ($difference->isGreaterThan(0)) {
            $lines[] = $ledger->line('fx_gain', '0', '0', '0', (string) $difference, 'Realised exchange-rate gain');
        } elseif ($difference->isLessThan(0)) {
            $lines[] = $ledger->line('fx_loss', '0', '0', (string) $difference->negated(), '0', 'Realised exchange-rate loss');
        }
        // On settlement reclassify the accrued unrealised FX for the portion
        // paid into realised FX without changing total earnings or bank funds.
        if ($unrealizedToReclass->isGreaterThan(0)) {
            $reclass = (string) $unrealizedToReclass->toScale(2);
            $lines[] = $ledger->line('unrealized_fx_gain', '0', '0', $reclass, '0',
                'Transfer settled portion of unrealised gain to realised income');
            $lines[] = $ledger->line('fx_gain', '0', '0', '0', $reclass,
                'Realised portion of previously accrued foreign-currency gain');
        } elseif ($unrealizedToReclass->isLessThan(0)) {
            $reclass = (string) $unrealizedToReclass->abs()->toScale(2);
            $lines[] = $ledger->line('unrealized_fx_loss', '0', '0', '0', $reclass,
                'Release settled portion of previously accrued unrealised loss');
            $lines[] = $ledger->line('fx_loss', '0', '0', $reclass, '0',
                'Realised portion of previously accrued foreign-currency loss');
        }

        return $lines;
    }

    private function inventoryReliefLines(FinanceInventoryRelief $relief): array
    {
        app(FinanceInventoryValuation::class)->validateBeforePost($relief);
        $amount = app(FinanceMath::class)->money((string) $relief->base_amount);
        $ledger = app(FinanceLedger::class);

        return [
            $ledger->line($relief->data_snapshot['expense_account'] ?? ($relief->kind === 'cogs' ? 'cogs' : 'waste_loss'),
                (string) $amount, '0', (string) $amount, '0', 'Source-batch inventory consumption'),
            $ledger->line('inventory', '0', (string) $amount, '0', (string) $amount,
                'Credited against the posted inventory valuation'),
        ];
    }

    private function costLines(FinanceShipmentCost $cost, string $rate): array
    {
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $amount = $math->money((string) $cost->amount);
        $base = $math->base($amount, $rate);
        $cash = $cost->cashAccount;
        abort_unless($cash && $cash->business_id === $cost->business_id, 422);
        app(FinanceBankProfiles::class)->assertPaymentAccount($cash, $cost->currency);

        return [
            $ledger->line('shipment_cost', (string) $amount, '0', (string) $base, '0', $cost->description),
            $ledger->line($cash->system_key, '0', (string) $amount, '0', (string) $base, 'Manually recorded expense payment'),
        ];
    }
}
