<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceCostClaim;
use App\Models\FinanceCostLayer;
use App\Models\FinanceInventoryRelief;
use App\Models\FinanceJournal;
use App\Models\FinanceOperation;
use App\Models\FinancePayrollRun;
use App\Models\FinanceWorkflow;
use App\Models\MaterialLot;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockBatchDepletion;
use App\Models\StockBatchOrigin;
use App\Models\StockExceptionCase;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceProductionCosts
{
    public const KINDS = ['material_receipt', 'material_issue', 'material_return', 'batch_cost', 'cost_release'];

    public function materialState(MaterialLot $lot): array
    {
        $qty = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $rows = [];
        foreach (DB::table('material_movements')->where('business_id', $lot->business_id)->where('material_lot_id', $lot->id)->orderBy('id')->get() as $r) {
            $qty = $qty->plus((string) $r->quantity);
            $cost = $cost->plus((string) $r->base_cost);
            $rows[] = (array) $r;
        }

        return ['quantity' => $qty, 'cost' => $cost, 'hash' => app(FinanceWorkflows::class)->hash($rows)];
    }

    private function source(Business $b, string $type, int $id, string $account, string $date): array
    {
        abort_unless(in_array($type, ['expense', 'payroll'], true), 422, 'Choose a posted expense or payroll source.');
        $source = ($type === 'expense' ? FinanceOperation::query() : FinancePayrollRun::query())->where('business_id', $b->id)->findOrFail($id);
        abort_unless($source->posting_status === 'posted', 422, 'Post the approved original cost first.');
        $keys = $type === 'expense' ? ['general_expense'] : ['payroll_expense', 'nssf_employer_expense'];
        abort_unless(in_array($account, $keys, true), 422, 'Choose an eligible original expense component.');
        $j = FinanceJournal::query()->where('business_id', $b->id)->where('source_type', $type === 'expense' ? 'money_operation' : 'payroll_run')->where('source_id', $id)->with('lines.account')->firstOrFail();
        abort_unless($j->posted_on->toDateString() <= $date, 422, 'Cost allocation cannot precede its original posting.');
        $total = BigDecimal::zero();
        foreach ($j->lines as $line) {
            if ($line->account->system_key === $account) {
                $total = $total->plus((string) $line->base_debit)->minus((string) $line->base_credit);
            }
        }
        if ($type === 'expense') {
            $corrected = app(FinanceMoneyCorrections::class)->corrected($source, true);
            $total = $total->minus(app(FinanceMath::class)->money($total->multipliedBy($corrected)->dividedBy((string) $source->amount, 14, RoundingMode::HalfUp)));
        }
        abort_unless($total->isGreaterThan(0), 422, 'This source has no uncorrected eligible cost.');

        return ['total' => $total, 'hash' => app(FinanceWorkflows::class)->hash([$source->snapshot_hash ?? $source->sha256, $j->toArray(), (string) $total])];
    }

    private function used(Business $b, string $type, int $id, string $account, ?int $except = null): BigDecimal
    {
        $used = BigDecimal::zero();
        foreach (FinanceCostClaim::query()->where('business_id', $b->id)->where('source_type', $type)->where('source_id', $id)->where('account', $account)->when($except, fn ($q) => $q->where('workflow_id', '!=', $except))->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->get() as $claim) {
            if (BigDecimal::of((string) $claim->base_amount)->isLessThan(0) && $claim->workflow->posting_status !== 'posted') {
                continue;
            }
            $used = $used->plus((string) $claim->base_amount);
        }

        return $used;
    }

    private function batchState(Business $b, StockBatch $batch, ?int $except = null): array
    {
        abort_if(StockAdjustment::query()->where('business_id', $b->id)->where('batch_id', $batch->id)->where('posting_status', 'not_posted')->whereIn('status', ['pending', 'approved'])->exists(), 422, 'Finish this batch count before changing its cost.');
        $basis = app(FinanceCostBasis::class)->batchBasis($batch);
        $q = ['cogs' => BigDecimal::zero(), 'waste' => BigDecimal::zero()];
        $rows = [];
        $reliefParts = [];
        foreach (FinanceInventoryRelief::query()->where('business_id', $b->id)->orderBy('id')->get() as $relief) {
            foreach ($relief->data_snapshot['lines'] ?? [] as $line) {
                if ((int) $line['batch_id'] === $batch->id) {
                    abort_unless($relief->posting_status === 'posted', 422, 'Finish pending cost relief before adding batch costs.');
                    $q[$relief->kind] = $q[$relief->kind]->plus($line['quantity']);
                    $rows[] = [$relief->id, $relief->sha256, $relief->posting_status];
                    $reliefParts[] = ['relief_id' => $relief->id, 'kind' => $relief->kind, 'quantity' => $line['quantity'], 'expense_account' => $relief->data_snapshot['expense_account'] ?? ($relief->kind === 'cogs' ? 'cogs' : 'waste_loss'), 'event_on' => FinanceJournal::query()->where('business_id', $b->id)->where('source_type', 'inventory_relief')->where('source_id', $relief->id)->firstOrFail()->posted_on->toDateString()];
                }
            }
        }
        $returnedIds = [];
        $returnedQuantity = BigDecimal::zero();
        foreach (StockExceptionCase::query()->where('business_id', $b->id)->where('batch_id', $batch->id)->where('kind', 'customer_return')->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->orderBy('id')->get() as $returned) {
            $returnedIds[] = $returned->id;
            $returnedQuantity = $returnedQuantity->plus((string) $returned->quantity);
            foreach ($reliefParts as &$part) {
                $relief = FinanceInventoryRelief::findOrFail($part['relief_id']);
                if ($relief->shipment_id === $returned->shipment_id) {
                    $part['quantity'] = (string) BigDecimal::of($part['quantity'])->minus((string) $returned->quantity);
                    $q['cogs'] = $q['cogs']->minus((string) $returned->quantity);
                }
            }unset($part);
            $rows[] = [$returned->id, $returned->workflow_id, $returned->workflow->snapshot_hash];
        }
        $eligibleQuantity = $basis['quantity']->minus($returnedQuantity);
        $reliefParts = array_values(array_filter($reliefParts, fn ($part) => BigDecimal::of($part['quantity'])->isGreaterThan(0)));
        $layers = FinanceCostLayer::query()->where('business_id', $b->id)->where('batch_id', $batch->id)->when($except, fn ($q) => $q->where('workflow_id', '!=', $except))->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->with('workflow')->orderBy('id')->get();
        foreach ($layers as $layer) {
            abort_unless($layer->workflow->posting_status === 'posted', 422, 'Finish or withdraw the pending batch cost first.');
            $rows[] = $layer->only(['id', 'base_amount']);
        }
        abort_unless($eligibleQuantity->isGreaterThan(0) && ! $q['cogs']->isLessThan(0) && ! $q['cogs']->plus($q['waste'])->isGreaterThan($eligibleQuantity), 422, 'Batch consumption does not reconcile.');

        return ['returned_ids' => $returnedIds, 'reliefs' => $reliefParts, 'quantity' => $eligibleQuantity, 'cogs' => $q['cogs'], 'waste' => $q['waste'], 'hash' => app(FinanceWorkflows::class)->hash([(string) $basis['quantity'], (string) $basis['cost'], $rows])];
    }

    public function prepare(Business $b, string $kind, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $kind, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($b, $kind, $data)) {
                return $old;
            }abort_unless(in_array($kind, self::KINDS, true), 422);
            if ($kind === 'cost_release') {
                return $this->prepareRelease($b, $data, $actor);
            }
            $math = app(FinanceMath::class);
            $ledger = app(FinanceLedger::class);
            $details = ['input' => $data];
            $claim = null;
            $layer = null;
            $movement = null;
            $amount = null;
            $credit = 'general_expense';
            if (in_array($kind, ['material_receipt', 'batch_cost'], true)) {
                $type = $data['source_type'];
                $id = (int) $data['source_id'];
                $credit = $data['source_account'];
                $source = $this->source($b, $type, $id, $credit, $data['event_on']);
                $amount = $math->money($data['base_amount']);
                abort_unless($amount->isGreaterThan(0) && ! $this->used($b, $type, $id, $credit)->plus($amount)->isGreaterThan($source['total']), 422, 'This exceeds the remaining source cost. Each approved cost can be allocated once.');
                $claim = ['source_type' => $type, 'source_id' => $id, 'account' => $credit, 'base_amount' => (string) $amount, 'source_hash' => $source['hash']];
            }
            if ($kind === 'material_receipt') {
                abort_unless($data['source_type'] === 'expense', 422, 'Material receipts must use an actual purchase expense. Labour belongs in the batch cost task.');
                abort_unless(BigDecimal::of($data['quantity'])->isGreaterThan(0), 422, 'Received materials must have a positive count.');
                $details['lot'] = array_intersect_key($data, array_flip(['name', 'unit', 'location', 'quantity']));
                $lines = [$ledger->line('materials_inventory', (string) $amount, '0', (string) $amount, '0', 'Verified material receipt'), $ledger->line($credit, '0', (string) $amount, '0', (string) $amount, 'Classify the original cost once')];
            } else {
                $batch = StockBatch::query()->where('business_id', $b->id)->lockForUpdate()->findOrFail($data['batch_id']);
                abort_unless(substr((string) $batch->received_on, 0, 10) <= $data['event_on'], 422, 'Batch costs cannot precede receiving.');
                $state = $this->batchState($b, $batch);
                $details['batch_hash'] = $state['hash'];
                $details['returned_case_ids'] = $state['returned_ids'];
                if (in_array($kind, ['material_issue', 'material_return'], true)) {
                    $lot = MaterialLot::query()->where('business_id', $b->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->lockForUpdate()->findOrFail($data['material_lot_id']);
                    $m = $this->materialState($lot);
                    $qty = BigDecimal::of($data['quantity']);
                    abort_unless($qty->isGreaterThan(0), 422, 'Enter a positive material quantity.');
                    $details['material_hash'] = $m['hash'];
                    $details['lot_id'] = $lot->id;
                    $credit = 'materials_inventory';
                    if ($kind === 'material_issue') {
                        abort_unless(! $qty->isGreaterThan($m['quantity']), 422, 'Not enough materials remain in this lot.');
                        $amount = $qty->isEqualTo($m['quantity']) ? $m['cost'] : $math->money($m['cost']->multipliedBy($qty)->dividedBy($m['quantity'], 14, RoundingMode::HalfUp));
                        $movement = ['material_lot_id' => $lot->id, 'quantity' => (string) $qty->negated(), 'base_cost' => (string) $amount->negated()];
                    } else {
                        $original = FinanceWorkflow::query()->where('business_id', $b->id)->where('kind', 'material_issue')->where('posting_status', 'posted')->findOrFail($data['original_workflow_id']);
                        $o = $original->data_snapshot['details'];
                        abort_unless((int) $o['lot_id'] === $lot->id && (int) $o['input']['batch_id'] === $batch->id, 422, 'Return unused materials to their original lot and batch.');
                        abort_unless($state['cogs']->plus($state['waste'])->isZero(), 422, 'Unused material returns require a batch with no valued consumption. Correct consumed costs through a documented cost review.');
                        $returned = BigDecimal::zero();
                        $returnedCost = BigDecimal::zero();
                        foreach (FinanceWorkflow::query()->where('business_id', $b->id)->where('kind', 'material_return')->whereIn('posting_status', ['posted', 'not_posted'])->get() as $r) {
                            if ((int) ($r->data_snapshot['details']['input']['original_workflow_id'] ?? 0) === $original->id) {
                                $returned = $returned->plus($r->data_snapshot['details']['input']['quantity']);
                                $returnedCost = $returnedCost->plus((string) $r->amount);
                            }
                        }$available = BigDecimal::of($o['input']['quantity'])->minus($returned);
                        abort_unless(! $qty->isGreaterThan($available), 422, 'Returns exceed the original issued materials.');
                        $amount = $qty->isEqualTo($available) ? BigDecimal::of((string) $original->amount)->minus($returnedCost) : $math->money(BigDecimal::of((string) $original->amount)->multipliedBy($qty)->dividedBy($o['input']['quantity'], 14, RoundingMode::HalfUp));
                        $movement = ['material_lot_id' => $lot->id, 'quantity' => (string) $qty, 'base_cost' => (string) $amount];
                    }
                }
                abort_unless($amount->isGreaterThan(0), 422, 'Cost is below ledger precision.');
                $cogs = $math->money($amount->multipliedBy($state['cogs'])->dividedBy($state['quantity'], 14, RoundingMode::HalfUp));
                $waste = $math->money($amount->multipliedBy($state['waste'])->dividedBy($state['quantity'], 14, RoundingMode::HalfUp));
                $inventory = $amount->minus($cogs)->minus($waste);
                $sign = $kind === 'material_return' ? -1 : 1;
                $details['relief_shares'] = [];
                foreach (['cogs' => $cogs, 'waste' => $waste] as $reliefKind => $reliefCost) {
                    $parts = array_values(array_filter($state['reliefs'], fn ($p) => $p['kind'] === $reliefKind));
                    $remainingShare = $reliefCost;
                    foreach ($parts as $index => $part) {
                        $share = $index === count($parts) - 1 ? $remainingShare : $math->money($reliefCost->multipliedBy($part['quantity'])->dividedBy($state[$reliefKind], 14, RoundingMode::HalfUp));
                        $remainingShare = $remainingShare->minus($share);
                        $details['relief_shares'][] = ['inventory_relief_id' => $part['relief_id'], 'quantity_covered' => $part['quantity'], 'event_on' => max($data['event_on'], $part['event_on']), 'expense_account' => $part['expense_account'], 'base_cost' => (string) $share->multipliedBy($sign)];
                    }
                }
                $layer = ['batch_id' => $batch->id, 'base_amount' => (string) $amount->multipliedBy($sign), 'inventory_amount' => (string) $inventory->multipliedBy($sign), 'cogs_amount' => (string) $cogs->multipliedBy($sign), 'waste_amount' => (string) $waste->multipliedBy($sign)];
                $lines = [$ledger->line('inventory', $sign === 1 ? (string) $amount : '0', $sign === 1 ? '0' : (string) $amount, $sign === 1 ? (string) $amount : '0', $sign === 1 ? '0' : (string) $amount, 'Reviewed production cost'), $ledger->line($credit, $sign === 1 ? '0' : (string) $amount, $sign === 1 ? (string) $amount : '0', $sign === 1 ? '0' : (string) $amount, $sign === 1 ? (string) $amount : '0', 'Reclassify original expense / material issue')];

            }
            $details['claim'] = $claim;
            $details['layer'] = $layer;
            $details['movement'] = $movement;
            $legs = [['currency' => $b->base_currency, 'rate_to_base' => '1', 'amount' => (string) $amount, 'lines' => $lines]];
            foreach ($details['relief_shares'] ?? [] as $share) {
                if (BigDecimal::of($share['base_cost'])->isGreaterThan(0)) {
                    $part = $share['base_cost'];
                    $legs[] = ['currency' => $b->base_currency, 'rate_to_base' => '1', 'event_on' => $share['event_on'], 'amount' => $part, 'lines' => [$ledger->line($share['expense_account'], $part, '0', $part, '0', 'Recognise the consumed cost share'), $ledger->line('inventory', '0', $part, '0', $part, 'Relieve consumed production cost')]];
                }
            }
            $flow = $flows->create($b, $kind, $data, $actor, $legs, $details);
            if ($claim) {
                FinanceCostClaim::create([...$claim, 'business_id' => $b->id, 'workflow_id' => $flow->id]);
            }if ($layer) {
                FinanceCostLayer::create([...$layer, 'business_id' => $b->id, 'workflow_id' => $flow->id]);
            }

            return $flow;
        }, 3);
    }

    public function validate(FinanceWorkflow $flow): void
    {
        $b = Business::findOrFail($flow->business_id);
        $d = $flow->data_snapshot['details'];
        $input = $d['input'];
        if (! empty($d['release_original_id'])) {
            $original = FinanceWorkflow::query()->where('business_id', $b->id)->findOrFail($d['release_original_id']);
            abort_unless($original->posting_status === 'posted' && $original->snapshot_hash === $d['release_original_hash'], 422, 'The original allocation changed.');
            $this->assertReleaseAvailable($b, $original, $flow->id);
        }
        if ($d['claim']) {
            $c = $d['claim'];
            $source = $this->source($b, $c['source_type'], $c['source_id'], $c['account'], $flow->event_on->toDateString());
            abort_unless($source['hash'] === $c['source_hash'] && ! $this->used($b, $c['source_type'], $c['source_id'], $c['account'], $flow->id)->plus($c['base_amount'])->isGreaterThan($source['total']), 422, 'Original cost changed or was already allocated.');
            $actual = FinanceCostClaim::query()->where('business_id', $b->id)->where('workflow_id', $flow->id)->firstOrFail();
            foreach ($c as $key => $value) {
                abort_unless($key === 'base_amount' ? BigDecimal::of((string) $actual->{$key})->isEqualTo((string) $value) : (string) $actual->{$key} === (string) $value, 422, 'Source cost claim changed.');
            }
        }
        if ($d['layer']) {
            $layer = FinanceCostLayer::query()->where('business_id', $b->id)->where('workflow_id', $flow->id)->firstOrFail();
            foreach ($d['layer'] as $key => $value) {
                abort_unless(in_array($key, ['batch_id'], true) ? $layer->{$key} === $value : BigDecimal::of((string) $layer->{$key})->isEqualTo((string) $value), 422, 'Batch cost allocation changed.');
            }
            $state = $this->batchState($b, StockBatch::query()->where('business_id', $b->id)->findOrFail($layer->batch_id), $flow->id);
            abort_unless($state['hash'] === $d['batch_hash'], 422, 'Batch cost or consumption changed. Withdraw and prepare a new allocation.');
        }
        if ($d['movement']) {
            $lot = MaterialLot::query()->where('business_id', $b->id)->findOrFail($d['movement']['material_lot_id']);
            abort_unless($this->materialState($lot)['hash'] === $d['material_hash'], 422, 'Material stock changed. Prepare the issue or return again.');
        }
    }

    public function finalize(FinanceWorkflow $flow): void
    {
        $d = $flow->data_snapshot['details'];
        if (! empty($d['relief_shares'])) {
            $layer = FinanceCostLayer::query()->where('business_id', $flow->business_id)->where('workflow_id', $flow->id)->firstOrFail();
            foreach ($d['relief_shares'] as $share) {
                DB::table('finance_cost_layer_reliefs')->insert([...$share, 'business_id' => $flow->business_id, 'cost_layer_id' => $layer->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($flow->kind === 'material_receipt') {
            $lot = MaterialLot::create([...$d['lot'], 'business_id' => $flow->business_id, 'workflow_id' => $flow->id, 'base_cost' => $flow->amount]);
            DB::table('material_movements')->insert(['business_id' => $flow->business_id, 'workflow_id' => $flow->id, 'material_lot_id' => $lot->id, 'quantity' => $lot->quantity, 'base_cost' => $lot->base_cost, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($d['movement']) {
            DB::table('material_movements')->insert([...$d['movement'], 'business_id' => $flow->business_id, 'workflow_id' => $flow->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function assertReleaseAvailable(Business $b, FinanceWorkflow $original, ?int $except = null): void
    {
        abort_if(FinanceWorkflow::query()->where('business_id', $b->id)->where('kind', 'cost_release')->whereIn('posting_status', ['posted', 'not_posted'])->when($except, fn ($q) => $q->whereKeyNot($except))->where('data_snapshot->details->release_original_id', $original->id)->exists(), 422, 'This original allocation already has a pending or posted release.');
        if ($original->kind === 'batch_cost') {
            $batch = $original->data_snapshot['details']['input']['batch_id'];
            abort_if(StockBatchOrigin::query()->where('business_id', $b->id)->where('source_batch_id', $batch)->exists() || StockBatchDepletion::query()->where('business_id', $b->id)->where('batch_id', $batch)->exists(), 422, 'Allocation release is blocked after stock has split or been returned. All affected cost layers require a reviewed reconstruction; this action cannot rewrite that history.');
            abort_if(StockAdjustment::query()->where('business_id', $b->id)->where('batch_id', $batch)->where('posting_status', 'posted')->exists(), 422, 'This batch has a posted count valuation. Resolve that dated count before releasing a source cost.');
        }
    }

    private function prepareRelease(Business $b, array $data, int $actor): FinanceWorkflow
    {
        $original = FinanceWorkflow::query()->where('business_id', $b->id)->whereIn('kind', ['material_receipt', 'batch_cost'])->where('posting_status', 'posted')->findOrFail($data['original_workflow_id']);
        $this->assertReleaseAvailable($b, $original);
        abort_unless($data['event_on'] >= $original->event_on->toDateString(), 422, 'Release an allocation on or after its original date.');
        $d = $original->data_snapshot['details'];
        $claim = $d['claim'];
        $amount = BigDecimal::of($claim['base_amount']);
        $ledger = app(FinanceLedger::class);
        $lines = [];
        $details = ['input' => $data, 'release_original_id' => $original->id, 'release_original_hash' => $original->snapshot_hash, 'claim' => [...$claim, 'base_amount' => (string) $amount->negated()], 'layer' => null, 'movement' => null, 'relief_shares' => []];
        if ($original->kind === 'material_receipt') {
            $lot = MaterialLot::query()->where('business_id', $b->id)->where('workflow_id', $original->id)->firstOrFail();
            $state = $this->materialState($lot);
            abort_unless($state['quantity']->isEqualTo((string) $lot->quantity) && $state['cost']->isEqualTo($amount), 422, 'Return all issued materials before correcting their original receipt allocation.');
            $details['material_hash'] = $state['hash'];
            $details['movement'] = ['material_lot_id' => $lot->id, 'quantity' => (string) $state['quantity']->negated(), 'base_cost' => (string) $amount->negated()];
            $lines[] = $ledger->line('materials_inventory', '0', (string) $amount, '0', (string) $amount, 'Release corrected original material classification');
        } else {
            $batch = StockBatch::query()->where('business_id', $b->id)->findOrFail($d['input']['batch_id']);
            $state = $this->batchState($b, $batch);
            foreach ($state['reliefs'] as $part) {
                abort_unless($data['event_on'] >= $part['event_on'], 422, 'Cost releases cannot precede the consumption they correct.');
            }
            $details['batch_hash'] = $state['hash'];
            $details['returned_case_ids'] = $state['returned_ids'];
            $math = app(FinanceMath::class);
            $cogs = $math->money($amount->multipliedBy($state['cogs'])->dividedBy($state['quantity'], 14, RoundingMode::HalfUp));
            $waste = $math->money($amount->multipliedBy($state['waste'])->dividedBy($state['quantity'], 14, RoundingMode::HalfUp));
            $inventory = $amount->minus($cogs)->minus($waste);
            $details['layer'] = ['batch_id' => $batch->id, 'base_amount' => (string) $amount->negated(), 'inventory_amount' => (string) $inventory->negated(), 'cogs_amount' => (string) $cogs->negated(), 'waste_amount' => (string) $waste->negated()];
            if ($inventory->isGreaterThan(0)) {
                $lines[] = $ledger->line('inventory', '0', (string) $inventory, '0', (string) $inventory, 'Release current stock share of corrected production classification');
            }
            foreach (['cogs' => $cogs, 'waste' => $waste] as $kind => $total) {
                $parts = array_values(array_filter($state['reliefs'], fn ($p) => $p['kind'] === $kind));
                $remaining = $total;
                foreach ($parts as $index => $part) {
                    $share = $index === count($parts) - 1 ? $remaining : $math->money($total->multipliedBy($part['quantity'])->dividedBy($state[$kind], 14, RoundingMode::HalfUp));
                    $remaining = $remaining->minus($share);
                    if ($share->isGreaterThan(0)) {
                        $lines[] = $ledger->line($part['expense_account'], '0', (string) $share, '0', (string) $share, 'Release consumed share of corrected production classification');
                    }
                    $details['relief_shares'][] = ['inventory_relief_id' => $part['relief_id'], 'quantity_covered' => $part['quantity'], 'event_on' => $data['event_on'], 'expense_account' => $part['expense_account'], 'base_cost' => (string) $share->negated()];
                }
            }
        }
        $lines[] = $ledger->line($claim['account'], (string) $amount, '0', (string) $amount, '0', 'Restore original expense before a separate correction or reallocation');
        $flow = app(FinanceWorkflows::class)->create($b, 'cost_release', $data, $actor, [['currency' => $b->base_currency, 'rate_to_base' => '1', 'amount' => (string) $amount, 'lines' => $lines]], $details);
        FinanceCostClaim::create([...$details['claim'], 'business_id' => $b->id, 'workflow_id' => $flow->id]);
        if ($details['layer']) {
            FinanceCostLayer::create([...$details['layer'], 'business_id' => $b->id, 'workflow_id' => $flow->id]);
        }

        return $flow;
    }

    public function assertUnallocated(int $businessId, string $type, int $sourceId): void
    {
        $allocated = BigDecimal::zero();
        foreach (FinanceCostClaim::query()->where('business_id', $businessId)->where('source_type', $type)->where('source_id', $sourceId)->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->with('workflow')->get() as $claim) {
            if (BigDecimal::of((string) $claim->base_amount)->isLessThan(0) && $claim->workflow->posting_status !== 'posted') {
                continue;
            }
            $allocated = $allocated->plus((string) $claim->base_amount);
        }
        abort_if($allocated->isGreaterThan(0), 422, 'This cost is allocated to materials or produce. Release its original cost allocation before correcting the expense/payroll.');
    }
}
