<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceCutover;
use App\Models\FinanceJournal;
use App\Models\FinanceOpeningItem;
use App\Models\FinanceOperation;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceWorkflow;
use App\Models\FinanceWorkflowLeg;
use App\Models\MarketTrip;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceOpeningItems
{
    public function position(FinanceOpeningItem $item, bool $pending = false, ?int $except = null, ?string $through = null): array
    {
        abort_unless(hash_equals($item->snapshot_hash, app(FinanceWorkflows::class)->hash($item->data_snapshot)), 422, 'Opening source evidence changed.');
        foreach (['reference', 'kind', 'currency', 'amount', 'base_amount'] as $field) {
            $value = $field === 'kind' ? $item->data_snapshot['type'] : $item->data_snapshot[$field];
            abort_unless(in_array($field, ['amount', 'base_amount'], true) ? BigDecimal::of((string) $item->{$field})->isEqualTo((string) $value) : $item->{$field} === $value, 422, 'An opening source field changed.');
        }
        foreach (['customer_id', 'supplier_id', 'staff_person_id'] as $party) {
            abort_unless((string) $item->{$party} === (string) ($item->data_snapshot[$party] ?? ''), 422, 'An opening balance party changed after sign-off.');
        }
        abort_unless(($item->due_on?->toDateString() ?? '') === ($item->data_snapshot['due_on'] ?? ''), 422, 'The original opening due date changed.');
        $cutover = FinanceCutover::query()->where('business_id', $item->business_id)->findOrFail($item->cutover_id);
        $leg = FinanceWorkflowLeg::query()->where('business_id', $item->business_id)->where('workflow_id', $item->workflow_id)->findOrFail($item->workflow_leg_id);
        $original = collect($cutover->data_snapshot['rows'])->first(fn ($row) => ($row['reference'] ?? null) === $item->reference && ($row['leg_sequence'] ?? null) === $leg->sequence);
        abort_unless($cutover->workflow_id === $item->workflow_id && $original === $item->data_snapshot && hash_equals($cutover->snapshot_hash, app(FinanceWorkflows::class)->hash($cutover->data_snapshot)), 422, 'The opening source no longer matches its signed manifest and posted currency leg.');
        [$key, $type] = FinanceCutovers::CONTROLS[$item->kind];
        $direction = $type === 'asset' ? 1 : -1;
        $amount = BigDecimal::zero();
        $base = BigDecimal::zero();
        $unrealized = BigDecimal::zero();
        $rows = [];
        $latest = '0000-00-00';
        $legIds = [$item->workflow_leg_id];
        $uses = DB::table('finance_opening_item_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $item->business_id)->where('w.business_id', $item->business_id)->where('u.opening_item_id', $item->id)->where('w.posting_status', 'posted')->when($except, fn ($q) => $q->where('u.workflow_id', '!=', $except))->pluck('u.workflow_id')->all();
        foreach (DB::table('finance_workflow_corrections as c')->join('finance_workflows as w', 'w.id', '=', 'c.workflow_id')->where('c.business_id', $item->business_id)->where('w.posting_status', 'posted')->whereIn('c.original_workflow_id', $uses)->pluck('c.workflow_id') as $id) {
            $uses[] = $id;
        }
        $legIds = [...$legIds, ...FinanceWorkflowLeg::query()->where('business_id', $item->business_id)->whereIn('workflow_id', $uses)->pluck('id')->all()];
        foreach (FinanceJournal::query()->where('business_id', $item->business_id)->where('source_type', 'workflow_leg')->whereIn('source_id', $legIds)->when($through, fn ($q) => $q->whereDate('posted_on', '<=', $through))->with('lines.account')->orderBy('id')->get() as $journal) {
            $latest = max($latest, $journal->posted_on->toDateString());
            foreach ($journal->lines as $line) {
                if ($line->account->system_key === $key) {
                    $amount = $amount->plus(BigDecimal::of((string) $line->debit)->minus((string) $line->credit)->multipliedBy($direction));
                    $base = $base->plus(BigDecimal::of((string) $line->base_debit)->minus((string) $line->base_credit)->multipliedBy($direction));
                }
                if ($line->account->system_key === 'unrealized_fx_gain') {
                    $unrealized = $unrealized->plus((string) $line->base_credit)->minus((string) $line->base_debit);
                }
                if ($line->account->system_key === 'unrealized_fx_loss') {
                    $unrealized = $unrealized->plus((string) $line->base_credit)->minus((string) $line->base_debit);
                }
            }
            foreach (DB::table('finance_cash_settlements')->where('business_id', $item->business_id)->where('source_type', 'workflow_leg')->where('source_id', $journal->source_id)->pluck('unrealized_released') as $release) {
                $unrealized = $unrealized->plus((string) $release);
            }
            $rows[] = app(FinanceManagementReports::class)->journalEvidence($journal);
        }
        $available = $amount;
        if ($pending) {
            foreach (DB::table('finance_opening_item_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $item->business_id)->where('u.opening_item_id', $item->id)->where('w.posting_status', 'not_posted')->when($except, fn ($q) => $q->where('u.workflow_id', '!=', $except))->pluck('u.amount') as $used) {
                $available = $available->minus((string) $used);
            }
        }
        abort_unless(! $amount->isLessThan(0) && ! $base->isLessThan(0), 422, 'Opening source applications exceed its remaining control balance.');

        return ['amount' => $amount, 'base' => $base, 'available' => $available, 'unrealized' => $unrealized, 'key' => $key, 'type' => $type, 'latest_on' => $latest, 'hash' => app(FinanceWorkflows::class)->hash([$item->snapshot_hash, $rows])];
    }

    public function prepare(Business $b, FinanceOpeningItem $item, string $kind, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $item, $kind, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            $data['opening_item_id'] = $item->id;
            if ($old = $flows->existing($b, $kind, $data)) {
                return $old;
            }
            abort_unless(in_array($kind, ['opening_item_settlement', 'opening_item_apply', 'opening_item_adjustment'], true), 422);
            $item = FinanceOpeningItem::query()->where('business_id', $b->id)->with('workflow')->findOrFail($item->id);
            abort_unless($item->workflow->posting_status === 'posted' && $data['event_on'] >= $item->workflow->event_on->toDateString(), 422, 'Post the original opening first and use an actual later settlement date.');
            $state = $this->position($item, true);
            abort_unless($data['event_on'] >= $state['latest_on'], 422, 'Use a date on or after the latest opening source settlement or valuation.');
            $amount = app(FinanceMath::class)->money($data['amount']);
            abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($state['available']), 422, 'This exceeds the unapplied opening balance.');
            $base = $amount->isEqualTo($state['amount']) ? $state['base'] : app(FinanceMath::class)->money($state['base']->multipliedBy($amount)->dividedBy($state['amount'], 14, RoundingMode::HalfUp));
            $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
            $rate = $kind === 'opening_item_settlement' ? app(FinanceRates::class)->rate($b, $item->currency, $rateId, $data['event_on']) : (string) FinanceWorkflowLeg::query()->where('business_id', $b->id)->findOrFail($item->workflow_leg_id)->rate_to_base;
            if ($kind !== 'opening_item_settlement') {
                $rateId = FinanceWorkflowLeg::query()->where('business_id', $b->id)->findOrFail($item->workflow_leg_id)->exchange_rate_id;
            }
            $key = $state['key'];
            $l = app(FinanceLedger::class);
            $asset = $state['type'] === 'asset';
            $cash = null;
            $cashDirection = null;
            $guards = [];
            $target = null;
            $targetType = null;
            $targetBase = null;
            $targetProof = null;
            $lines = [$l->line($key, $asset ? '0' : (string) $amount, $asset ? (string) $amount : '0', $asset ? '0' : (string) $base, $asset ? (string) $base : '0', 'Apply original opening '.$item->reference)];
            if ($kind === 'opening_item_settlement') {
                $cash = app(FinanceTreasury::class)->moneyAccount($b, [...$data, 'currency' => $item->currency]);
                $cashDirection = $asset ? 'in' : 'out';
                $paidBase = app(FinanceMath::class)->base($amount, $rate);
                if (! $asset) {
                    $basis = $flows->accountBasis($b->id, $cash->id, $data['event_on']);
                    abort_unless(! $amount->isGreaterThan($basis['units']), 422, 'Actual payment exceeds posted money available.');
                    $guards[] = ['type' => 'account', 'id' => $cash->id, 'fingerprint' => $basis['fingerprint']];
                }
                $lines[] = $l->line($cash->system_key, $asset ? (string) $amount : '0', $asset ? '0' : (string) $amount, $asset ? (string) $paidBase : '0', $asset ? '0' : (string) $paidBase, 'Actual opening debt/advance settlement');
            } elseif ($kind === 'opening_item_apply') {
                abort_unless(in_array($item->kind, ['supplier_advance', 'staff_advance', 'customer_deposit'], true), 422, 'Only opening advances/deposits can be applied to real new bills or invoices.');
                $targetType = $data['target_type'];
                $target = match ($targetType) {
                    'supplier_bill' => FinanceSupplierBill::query(), 'commercial_invoice' => CommercialInvoice::query(), 'expense' => FinanceOperation::query(), default => abort(422, 'Choose a real bill, invoice or expense.')
                };
                $target = $target->where('business_id', $b->id)->where('posting_status', 'posted')->findOrFail($data['target_id']);
                abort_unless($target->currency === $item->currency, 422, 'An opening application uses the same currency. Record an actual conversion separately.');
                $valid = match ($item->kind) {
                    'customer_deposit' => $target instanceof CommercialInvoice && $target->customer_id === $item->customer_id, 'supplier_advance' => $target instanceof FinanceSupplierBill && $target->purchase->supplier_id === $item->supplier_id, 'staff_advance' => ($target instanceof FinanceSupplierBill && $target->purchase->market_trip_id && MarketTrip::query()->where('business_id', $b->id)->whereKey($target->purchase->market_trip_id)->where('staff_person_id', $item->staff_person_id)->exists()) || ($target instanceof FinanceOperation && $target->staff_person_id === $item->staff_person_id && in_array($target->kind, ['expense', 'market_reimbursement'], true)), default => false
                };
                abort_unless($valid, 422, 'The opening advance/deposit and actual source must belong to the same accountable party.');
                $outstanding = $target instanceof FinanceOperation ? app(FinanceOperations::class)->remaining($target) : app(FinanceDocuments::class)->outstanding($target);
                abort_unless(! $amount->isGreaterThan($outstanding), 422, 'Application exceeds the actual unpaid target.');
                if ($target instanceof FinanceOperation) {
                    $carry = app(FinanceMath::class)->money(app(FinanceOperations::class)->remainingControlBase($target)->multipliedBy($amount)->dividedBy(app(FinanceOperations::class)->remaining($target, false), 14, RoundingMode::HalfUp));
                } elseif ($item->currency === $b->base_currency) {
                    $carry = $amount;
                } else {
                    $p = app(FinanceMonetaryRevaluations::class)->position($b, $targetType, $target->id);
                    $carry = app(FinanceMath::class)->money(BigDecimal::of($p['previous_base'])->multipliedBy($amount)->dividedBy($p['foreign_balance'], 14, RoundingMode::HalfUp));
                }
                $originalTarget = FinanceJournal::query()->where('business_id', $b->id)->where('source_type', $targetType === 'expense' ? 'money_operation' : $targetType)->where('source_id', $target->id)->firstOrFail();
                abort_unless($data['event_on'] >= $originalTarget->posted_on->toDateString(), 422, 'An opening application cannot precede its actual target posting.');
                $targetProof = app(FinanceWorkflows::class)->hash([$target->getAttributes(), (string) $outstanding, (string) $carry]);
                if (! ($target instanceof FinanceOperation) && $item->currency !== $b->base_currency) {
                    $this->releaseUnrealized($lines, BigDecimal::of($p['unrealized_open']), $amount, BigDecimal::of($p['foreign_balance']));
                }
                $targetBase = (string) $carry;
                $account = $targetType === 'commercial_invoice' ? 'receivable' : ($targetType === 'supplier_bill' ? 'payable' : 'expense_payable');
                $lines[] = $l->line($account, $asset ? (string) $amount : '0', $asset ? '0' : (string) $amount, $asset ? (string) $carry : '0', $asset ? '0' : (string) $carry, 'Settle actual source with verified opening advance/deposit');
            } else {
                $lines[] = $l->line($asset ? 'opening_source_loss' : 'opening_source_recovery', $asset ? (string) $amount : '0', $asset ? '0' : (string) $amount, $asset ? (string) $base : '0', $asset ? '0' : (string) $base, 'Explicit supported opening balance correction/write-off');
            }
            $this->releaseUnrealized($lines, $state['unrealized'], $amount, $state['amount']);
            $net = BigDecimal::zero();
            foreach ($lines as $line) {
                $net = $net->plus($line['base_debit'])->minus($line['base_credit']);
            }
            if (! $net->isZero()) {
                $lines[] = $l->line($net->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $net->isLessThan(0) ? (string) $net->abs() : '0', $net->isGreaterThan(0) ? (string) $net : '0', 'Opening source settlement exchange difference');
            }
            $details = ['opening_item_id' => $item->id, 'source_hash' => $state['hash'], 'amount' => (string) $amount, 'control_base' => (string) $base, 'target_type' => $targetType, 'target_id' => $target?->id, 'target_control_base' => $targetBase, 'target_hash' => $targetProof];
            $flow = $flows->create($b, $kind, $data, $actor, [['currency' => $item->currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $rateId, 'amount' => (string) $amount, 'cash_account_id' => $cash?->id, 'cash_direction' => $cashDirection, 'lines' => $lines]], $details, $guards);
            DB::table('finance_opening_item_uses')->insert(['business_id' => $b->id, 'opening_item_id' => $item->id, 'workflow_id' => $flow->id, 'kind' => $kind, 'amount' => (string) $amount, 'control_base' => (string) $base, 'target_control_base' => $targetBase, 'commercial_invoice_id' => $target instanceof CommercialInvoice ? $target->id : null, 'supplier_bill_id' => $target instanceof FinanceSupplierBill ? $target->id : null, 'expense_id' => $target instanceof FinanceOperation ? $target->id : null, 'created_at' => now(), 'updated_at' => now()]);

            return $flow;
        }, 3);
    }

    private function releaseUnrealized(array &$lines, BigDecimal $unrealized, BigDecimal $part, BigDecimal $total): void
    {
        if ($unrealized->isZero()) {
            return;
        }
        $value = $part->isEqualTo($total) ? $unrealized->abs() : app(FinanceMath::class)->money($unrealized->abs()->multipliedBy($part)->dividedBy($total, 14, RoundingMode::HalfUp));
        $l = app(FinanceLedger::class);
        $gain = $unrealized->isGreaterThan(0);
        if ($value->isGreaterThan(0)) {
            $lines[] = $l->line($gain ? 'unrealized_fx_gain' : 'fx_loss', '0', '0', (string) $value, '0', 'Release settled opening source FX');
            $lines[] = $l->line($gain ? 'fx_gain' : 'unrealized_fx_loss', '0', '0', '0', (string) $value, 'Realise only the settled foreign balance');
        }
    }

    public function revalue(Business $b, FinanceOpeningItem $item, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $item, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            $data['opening_item_id'] = $item->id;
            if ($old = $flows->existing($b, 'opening_item_revaluation', $data)) {
                return $old;
            }
            $item = FinanceOpeningItem::query()->where('business_id', $b->id)->findOrFail($item->id);
            $state = $this->position($item, true);
            abort_unless($item->currency !== $b->base_currency && $state['amount']->isGreaterThan(0) && $state['available']->isEqualTo($state['amount']) && $data['event_on'] >= $state['latest_on'], 422, 'Revalue an outstanding foreign opening balance after finishing its pending settlements.');
            $rateId = (int) $data['exchange_rate_id'];
            $rate = app(FinanceRates::class)->rate($b, $item->currency, $rateId, $data['event_on']);
            $target = app(FinanceMath::class)->base($state['amount'], $rate);
            $change = $target->minus($state['base']);
            abort_unless(! $change->isZero(), 422, 'The foreign opening already has this carrying value.');
            $debit = ($state['type'] === 'asset') === $change->isGreaterThan(0);
            $value = (string) $change->abs();
            $l = app(FinanceLedger::class);
            $lines = [$l->line($state['key'], '0', '0', $debit ? $value : '0', $debit ? '0' : $value, 'Review outstanding opening source at the approved reporting rate'), $l->line($debit ? 'unrealized_fx_gain' : 'unrealized_fx_loss', '0', '0', $debit ? '0' : $value, $debit ? $value : '0', 'Unrealised opening source translation')];
            $flow = $flows->create($b, 'opening_item_revaluation', $data, $actor, [['currency' => $item->currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $rateId, 'amount' => (string) $state['amount'], 'lines' => $lines]], ['opening_item_id' => $item->id, 'source_hash' => $state['hash'], 'amount' => '0.00', 'control_base' => '0.00', 'target_type' => null, 'target_id' => null, 'revalued_base' => (string) $target]);
            DB::table('finance_opening_item_uses')->insert(['business_id' => $b->id, 'opening_item_id' => $item->id, 'workflow_id' => $flow->id, 'kind' => 'opening_item_revaluation', 'amount' => '0', 'control_base' => '0', 'created_at' => now(), 'updated_at' => now()]);

            return $flow;
        }, 3);
    }

    public function usedByTarget(int $businessId, string $field, int $id, bool $pending = true, ?int $except = null): array
    {
        abort_unless(in_array($field, ['commercial_invoice_id', 'supplier_bill_id', 'expense_id'], true), 422);
        $amount = BigDecimal::zero();
        $base = BigDecimal::zero();
        foreach (DB::table('finance_opening_item_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $businessId)->where('w.business_id', $businessId)->where('u.'.$field, $id)->whereIn('w.posting_status', $pending ? ['not_posted', 'posted'] : ['posted'])->when($except, fn ($q) => $q->where('u.workflow_id', '!=', $except))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_workflow_corrections as c')->join('finance_workflows as cw', 'cw.id', '=', 'c.workflow_id')->whereColumn('c.business_id', 'u.business_id')->whereColumn('c.original_workflow_id', 'u.workflow_id')->where('cw.posting_status', 'posted'))->get(['u.amount', 'u.target_control_base']) as $use) {
            $amount = $amount->plus((string) $use->amount);
            $base = $base->plus((string) ($use->target_control_base ?? '0'));
        }

        return compact('amount', 'base');
    }

    public function validate(FinanceWorkflow $flow): void
    {
        $d = $flow->data_snapshot['details'];
        $item = FinanceOpeningItem::query()->where('business_id', $flow->business_id)->findOrFail($d['opening_item_id']);
        $state = $this->position($item, true, $flow->id);
        abort_unless($state['hash'] === $d['source_hash'] && ! $state['available']->isLessThan($d['amount']), 422, 'The opening source balance changed or was already used.');
        if ($d['target_id']) {
            $target = match ($d['target_type']) {
                'supplier_bill' => FinanceSupplierBill::query(), 'commercial_invoice' => CommercialInvoice::query(), 'expense' => FinanceOperation::query(), default => abort(422)
            };
            $target = $target->where('business_id', $flow->business_id)->where('posting_status', 'posted')->findOrFail($d['target_id']);
            $outstanding = $target instanceof FinanceOperation ? app(FinanceOperations::class)->remaining($target, true, null, $flow->id) : app(FinanceDocuments::class)->outstanding($target, true, $flow->id);
            abort_unless(! $outstanding->isLessThan($d['amount']), 422, 'The actual target was already settled.');
            $amount = BigDecimal::of($d['amount']);
            if ($target instanceof FinanceOperation) {
                $carry = app(FinanceMath::class)->money(app(FinanceOperations::class)->remainingControlBase($target)->multipliedBy($amount)->dividedBy(app(FinanceOperations::class)->remaining($target, false), 14, RoundingMode::HalfUp));
            } elseif ($item->currency === Business::findOrFail($flow->business_id)->base_currency) {
                $carry = $amount;
            } else {
                $position = app(FinanceMonetaryRevaluations::class)->position(Business::findOrFail($flow->business_id), $d['target_type'], $target->id);
                $carry = app(FinanceMath::class)->money(BigDecimal::of($position['previous_base'])->multipliedBy($amount)->dividedBy($position['foreign_balance'], 14, RoundingMode::HalfUp));
            }
            abort_unless(app(FinanceWorkflows::class)->hash([$target->getAttributes(), (string) $outstanding, (string) $carry]) === $d['target_hash'], 422, 'The actual target carrying value or source changed. Withdraw and prepare its application again.');

        }
    }
}
