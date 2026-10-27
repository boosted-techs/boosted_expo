<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\FinanceBalanceAdjustment;
use App\Models\FinanceCreditNote;
use App\Models\FinanceJournal;
use App\Models\FinanceSupplierBill;
use App\Models\FinanceTradeCredit;
use App\Models\FinanceWorkflow;
use App\Models\GoodsReceipt;
use App\Models\StockExceptionCase;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class FinanceTradeCredits
{
    public const KINDS = ['trade_credit', 'trade_credit_settlement', 'trade_credit_apply', 'supplier_return_loss'];

    private function document(Business $b, string $type, int $id): CommercialInvoice|FinanceSupplierBill
    {
        abort_unless(in_array($type, ['customer', 'supplier'], true), 422);

        return ($type === 'customer' ? CommercialInvoice::query() : FinanceSupplierBill::query())->where('business_id', $b->id)->where('posting_status', 'posted')->findOrFail($id);
    }

    private function state(Business $b, CommercialInvoice|FinanceSupplierBill $doc, ?int $except = null): array
    {
        $customer = $doc instanceof CommercialInvoice;
        $field = $customer ? 'commercial_invoice_id' : 'supplier_bill_id';
        $total = BigDecimal::of((string) ($customer ? $doc->grand_total : $doc->total));
        $credited = BigDecimal::zero();
        $taxCredited = BigDecimal::zero();
        $credits = [];
        abort_if($customer && FinanceCreditNote::query()->where('business_id', $b->id)->where($field, $doc->id)->exists(), 422, 'Resolve the existing full-credit workflow before another credit.');
        foreach (FinanceBalanceAdjustment::query()->where('business_id', $b->id)->where($field, $doc->id)->whereIn('posting_status', ['posted', 'not_posted'])->get() as $r) {
            $credited = $credited->plus((string) $r->amount);
            $credits[] = [$r->id, $r->sha256, $r->posting_status];
        }
        foreach (FinanceTradeCredit::query()->where('business_id', $b->id)->where($field, $doc->id)->when($except, fn ($q) => $q->where('workflow_id', '!=', $except))->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->with('workflow')->orderBy('id')->get() as $r) {
            $credited = $credited->plus((string) $r->amount);
            $taxCredited = $taxCredited->plus((string) $r->tax_amount);
            $credits[] = [$r->id, $r->workflow->snapshot_hash, $r->workflow->posting_status];
        }
        $original = FinanceJournal::query()->where('business_id', $b->id)->where('source_type', $customer ? 'commercial_invoice' : 'supplier_bill')->where('source_id', $doc->id)->with('lines.account')->firstOrFail();
        $out = app(FinanceDocuments::class)->outstanding($doc, true, $except);
        $posted = app(FinanceDocuments::class)->outstanding($doc, false, $except);
        $foreign = $doc->currency !== $b->base_currency ? app(FinanceMonetaryRevaluations::class)->position($b, $customer ? 'commercial_invoice' : 'supplier_bill', $doc->id) : null;

        return ['available' => $total->minus($credited), 'tax_available' => BigDecimal::of((string) ($customer ? $doc->tax_amount : '0'))->minus($taxCredited), 'outstanding' => $out, 'posted_outstanding' => $posted, 'original' => $original, 'foreign' => $foreign, 'hash' => app(FinanceWorkflows::class)->hash([$doc->only(['id', 'posting_status', 'sha256', 'receipt_snapshot']), $credits, (string) $out, (string) $posted, $foreign, $original->toArray()])];
    }

    private function portion(BigDecimal $value, BigDecimal $part, BigDecimal $total): BigDecimal
    {
        if ($part->isZero()) {
            return BigDecimal::zero();
        }

        return $part->isEqualTo($total) ? $value : app(FinanceMath::class)->money($value->multipliedBy($part)->dividedBy($total, 14, RoundingMode::HalfUp));
    }

    private function balance(array &$lines): void
    {
        $net = BigDecimal::zero();
        foreach ($lines as $line) {
            $net = $net->plus($line['base_debit'])->minus($line['base_credit']);
        }if (! $net->isZero()) {
            $lines[] = app(FinanceLedger::class)->line($net->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $net->isLessThan(0) ? (string) $net->abs() : '0', $net->isGreaterThan(0) ? (string) $net : '0', 'Historical and current carrying value difference on agreed credit');
        }
    }

    private function reclass(array &$lines, array $state, BigDecimal $applied): void
    {
        if (! $state['foreign'] || $applied->isZero()) {
            return;
        }$unreal = $this->portion(BigDecimal::of($state['foreign']['unrealized_open']), $applied, $state['posted_outstanding']);
        if ($unreal->isZero()) {
            return;
        }$l = app(FinanceLedger::class);
        $v = (string) $unreal->abs();
        if ($unreal->isGreaterThan(0)) {
            $lines[] = $l->line('unrealized_fx_gain', '0', '0', $v, '0', 'Release settled unrealised document gain');
            $lines[] = $l->line('fx_gain', '0', '0', '0', $v, 'Realise settled document gain');
        } else {
            $lines[] = $l->line('unrealized_fx_loss', '0', '0', '0', $v, 'Release settled unrealised document loss');
            $lines[] = $l->line('fx_loss', '0', '0', $v, '0', 'Realise settled document loss');
        }
    }

    private function linkCase(Business $b, StockExceptionCase $case, CommercialInvoice|FinanceSupplierBill $doc): void
    {
        abort_unless(in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'Link the credit to an open claim or a posted physical return.');
        $d = app(StockExceptionCases::class)->verify($case);
        if ($doc instanceof CommercialInvoice) {
            abort_unless(in_array($case->kind, ['customer_return', 'quality_claim', 'recall'], true), 422, 'Use a customer return or affected shipment claim.');
            $ids = $case->kind === 'customer_return' ? [$case->shipment_id] : collect($d['source']['cartons'] ?? [])->pluck('shipment_id')->filter()->all();
            abort_unless(in_array($doc->shipment_id, $ids, true), 422, 'This invoice does not belong to an affected shipment.');
        } else {
            abort_unless(in_array($case->kind, ['supplier_return', 'quality_claim', 'recall'], true), 422, 'Use a supplier return or related quality case.');
            $receipt = GoodsReceipt::query()->where('business_id', $b->id)->where('purchase_order_id', $doc->purchase_order_id)->whereIn('batch_id', app(StockExceptionCases::class)->ancestors($b->id, $case->batch_id))->first();
            abort_unless($receipt, 422, 'The supplier bill must cover this batch’s source purchase.');
            if ($case->kind === 'supplier_return') {
                if ($case->resolution_workflow_id) {
                    abort_if(FinanceWorkflow::query()->where('business_id', $b->id)->whereKey($case->resolution_workflow_id)->whereIn('posting_status', ['posted', 'not_posted'])->exists(), 422, 'This supplier claim already has a credit or loss decision.');
                }abort_unless($case->workflow?->posting_status === 'posted', 422, 'Post the supplier return quantity and value before agreeing its claim credit.');
                abort_if(FinanceTradeCredit::query()->where('business_id', $b->id)->where('case_id', $case->id)->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->exists(), 422, 'This supplier-return claim already has a credit.');
            }
        }
    }

    public function prepare(Business $b, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($b, 'trade_credit', $data)) {
                return $old;
            }$doc = $this->document($b, $data['document_type'], (int) $data['document_id']);
            $customer = $doc instanceof CommercialInvoice;
            $case = StockExceptionCase::query()->where('business_id', $b->id)->with('workflow')->findOrFail($data['case_id']);
            $this->linkCase($b, $case, $doc);
            $this->assertNoPending($b->id, $customer ? 'commercial_invoice_id' : 'supplier_bill_id', $doc->id);
            $state = $this->state($b, $doc);
            $amount = app(FinanceMath::class)->money($data['amount']);
            $tax = app(FinanceMath::class)->money($data['tax_amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($state['available']) && ! $tax->isLessThan(0) && ! $tax->isGreaterThan($amount) && ! $tax->isGreaterThan($state['tax_available']), 422, 'Credit or tax exceeds the original uncredited document amount.');
            abort_unless($data['event_on'] >= $state['original']->posted_on->toDateString() && (! $state['foreign'] || $data['event_on'] >= $state['foreign']['latest_journal_on']), 422, 'Credit cannot precede its original document or latest settlement/revaluation.');
            $applied = BigDecimal::min($amount, BigDecimal::max('0', $state['outstanding']));
            $excess = $amount->minus($applied);
            $rate = (string) $state['original']->rate_to_base;
            $base = app(FinanceMath::class)->base($amount, $rate);
            $taxBase = app(FinanceMath::class)->base($tax, $rate);
            $carry = $state['foreign'] ? $this->portion(BigDecimal::of($state['foreign']['previous_base']), $applied, $state['posted_outstanding']) : $applied;
            $excessBase = app(FinanceMath::class)->base($excess, $rate);
            $l = app(FinanceLedger::class);
            $lines = [];
            if ($customer) {
                if ($amount->minus($tax)->isGreaterThan(0)) {
                    $lines[] = $l->line('sales_returns', (string) $amount->minus($tax), '0', (string) $base->minus($taxBase), '0', 'Agreed customer credit against original sale');
                }if ($tax->isGreaterThan(0)) {
                    $lines[] = $l->line('sales_tax', (string) $tax, '0', (string) $taxBase, '0', 'Agreed tax credit against original invoice');
                }if ($applied->isGreaterThan(0)) {
                    $lines[] = $l->line('receivable', '0', (string) $applied, '0', (string) $carry, 'Credit applied to unpaid original invoice');
                }if ($excess->isGreaterThan(0)) {
                    $lines[] = $l->line('customer_credit', '0', (string) $excess, '0', (string) $excessBase, 'Customer credit held for refund or future application');
                }
            } else {
                if ($applied->isGreaterThan(0)) {
                    $lines[] = $l->line('payable', (string) $applied, '0', (string) $carry, '0', 'Supplier credit against unpaid original bill');
                }if ($excess->isGreaterThan(0)) {
                    $lines[] = $l->line('supplier_credit_receivable', (string) $excess, '0', (string) $excessBase, '0', 'Supplier owes a refund or future bill credit');
                }$lines[] = $l->line($case->kind === 'supplier_return' ? 'trade_credit_clearing' : 'quality_claim_recovery', '0', (string) $amount, '0', (string) $base, 'Agreed supplier return / quality compensation');
            }
            $this->balance($lines);
            $this->reclass($lines, $state, $applied);
            $legs = [['currency' => $doc->currency, 'rate_to_base' => $rate, 'amount' => (string) $amount, 'lines' => $lines]];
            $claimBase = null;
            if (! $customer && $case->kind === 'supplier_return') {
                $claimBase = BigDecimal::of($case->workflow->data_snapshot['details']['value']['base_cost']);
                $rows = [$l->line('trade_credit_clearing', (string) $base, '0', (string) $base, '0', 'Clear the agreed supplier credit'), $l->line('supplier_return_pending', '0', (string) $claimBase, '0', (string) $claimBase, 'Resolve the original returned-goods claim')];
                $diff = $base->minus($claimBase);
                if (! $diff->isZero()) {
                    $rows[] = $l->line($diff->isGreaterThan(0) ? 'quality_claim_recovery' : 'supplier_return_loss', $diff->isLessThan(0) ? (string) $diff->abs() : '0', $diff->isGreaterThan(0) ? (string) $diff : '0', $diff->isLessThan(0) ? (string) $diff->abs() : '0', $diff->isGreaterThan(0) ? (string) $diff : '0', 'Agreed recovery compared with returned stock carrying value');
                }$legs[] = ['currency' => $b->base_currency, 'rate_to_base' => '1', 'amount' => (string) $base, 'lines' => $rows];
            }
            $supplier = $customer ? null : $doc->purchase?->supplier_id;
            $credit = ['case_id' => $case->id, 'commercial_invoice_id' => $customer ? $doc->id : null, 'supplier_bill_id' => $customer ? null : $doc->id, 'customer_id' => $customer ? $doc->customer_id : null, 'supplier_id' => $supplier, 'currency' => $doc->currency, 'amount' => (string) $amount, 'tax_amount' => (string) $tax, 'applied_amount' => (string) $applied, 'excess_amount' => (string) $excess, 'excess_base' => (string) $excessBase, 'due_on' => $data['due_on'] ?? null];
            $flow = $flows->create($b, 'trade_credit', $data, $actor, $legs, ['document_type' => $data['document_type'], 'document_id' => $doc->id, 'document_hash' => $state['hash'], 'credit' => $credit, 'case_hash' => $case->snapshot_hash]);
            FinanceTradeCredit::create([...$credit, 'business_id' => $b->id, 'workflow_id' => $flow->id]);
            if ($case->kind === 'supplier_return') {
                $case->update(['resolution_workflow_id' => $flow->id]);
            }

            return $flow;
        }, 3);
    }

    public function remaining(FinanceTradeCredit $credit, bool $pending = true, ?int $except = null): array
    {
        abort_unless($credit->workflow?->posting_status === 'posted', 422, 'Post the agreed credit before refunding or applying its excess.');
        $qty = BigDecimal::of((string) $credit->excess_amount);
        $base = BigDecimal::of((string) $credit->excess_base);
        $rows = [];
        foreach (DB::table('finance_trade_credit_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $credit->business_id)->where('w.business_id', $credit->business_id)->where('u.trade_credit_id', $credit->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_workflow_corrections as fc')->join('finance_workflows as cw', 'cw.id', '=', 'fc.workflow_id')->whereColumn('fc.business_id', 'u.business_id')->whereColumn('fc.original_workflow_id', 'u.workflow_id')->where('cw.posting_status', 'posted'))->whereIn('w.posting_status', $pending ? ['posted', 'not_posted'] : ['posted'])->when($except, fn ($q) => $q->where('u.workflow_id', '!=', $except))->orderBy('u.id')->get(['u.*', 'w.snapshot_hash', 'w.posting_status']) as $use) {
            $qty = $qty->minus((string) $use->amount);
            $base = $base->minus((string) $use->control_base);
            $rows[] = (array) $use;
        }

        return ['amount' => $qty, 'base' => $base, 'hash' => app(FinanceWorkflows::class)->hash([$credit->toArray(), $credit->workflow->snapshot_hash, $rows])];
    }

    public function use(Business $b, FinanceTradeCredit $credit, string $kind, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $credit, $kind, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($b, $kind, $data)) {
                return $old;
            }abort_unless(in_array($kind, ['trade_credit_settlement', 'trade_credit_apply'], true), 422);
            $credit = FinanceTradeCredit::query()->where('business_id', $b->id)->with('workflow')->findOrFail($credit->id);
            $r = $this->remaining($credit);
            abort_if(DB::table('finance_trade_credit_uses as u')->join('finance_workflows as w', 'w.id', '=', 'u.workflow_id')->where('u.business_id', $b->id)->where('w.business_id', $b->id)->where('u.trade_credit_id', $credit->id)->where('w.posting_status', 'not_posted')->exists(), 422, 'Finish the pending use of this held credit first.');
            $amount = app(FinanceMath::class)->money($data['amount']);
            abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($r['amount']) && $data['event_on'] >= $credit->workflow->event_on->toDateString(), 422, 'This exceeds the unused credit or precedes its agreement.');
            $customer = $credit->commercial_invoice_id !== null;
            $key = $customer ? 'customer_credit' : 'supplier_credit_receivable';
            $control = $this->portion($r['base'], $amount, $r['amount']);
            $l = app(FinanceLedger::class);
            $guards = [];
            $details = ['credit_id' => $credit->id, 'credit_hash' => $r['hash']];
            $cash = null;
            $direction = null;
            $doc = null;
            if ($kind === 'trade_credit_apply') {
                $doc = $this->document($b, $customer ? 'customer' : 'supplier', (int) $data['document_id']);
                abort_unless($doc->currency === $credit->currency && ($customer ? $doc->customer_id === $credit->customer_id : ($credit->supplier_id !== null && $doc->purchase?->supplier_id === $credit->supplier_id)), 422, 'Apply credit only to the same customer/supplier and currency. A market vendor with no registered identity requires an actual refund.');
                $this->assertNoPending($b->id, $customer ? 'commercial_invoice_id' : 'supplier_bill_id', $doc->id);
                $state = $this->state($b, $doc);
                abort_unless(! $amount->isGreaterThan($state['outstanding']), 422, 'Credit application exceeds the unpaid target document.');
                $rate = (string) $state['original']->rate_to_base;
                $carry = $state['foreign'] ? $this->portion(BigDecimal::of($state['foreign']['previous_base']), $amount, $state['posted_outstanding']) : $amount;
                $lines = $customer ? [$l->line($key, (string) $amount, '0', (string) $control, '0', 'Apply held customer credit'), $l->line('receivable', '0', (string) $amount, '0', (string) $carry, 'Apply credit to target invoice')] : [$l->line('payable', (string) $amount, '0', (string) $carry, '0', 'Apply supplier refund credit to target bill'), $l->line($key, '0', (string) $amount, '0', (string) $control, 'Supplier credit applied')];
                $this->balance($lines);
                $this->reclass($lines, $state, $amount);
                $details['document_type'] = $customer ? 'customer' : 'supplier';
                $details['document_id'] = $doc->id;
                $details['document_hash'] = $state['hash'];
                $rateId = null;
            } else {
                $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
                $rate = app(FinanceRates::class)->rate($b, $credit->currency, $rateId, $data['event_on']);
                $cash = app(FinanceTreasury::class)->moneyAccount($b, [...$data, 'currency' => $credit->currency]);
                $base = app(FinanceMath::class)->base($amount, $rate);
                $direction = $customer ? 'out' : 'in';
                if ($customer) {
                    $basis = $flows->accountBasis($b->id, $cash->id, $data['event_on']);
                    abort_unless(! $amount->isGreaterThan($basis['units']), 422, 'The actual refund exceeds posted money available.');
                    $guards[] = ['type' => 'account', 'id' => $cash->id, 'fingerprint' => $basis['fingerprint']];
                }$lines = $customer ? [$l->line($key, (string) $amount, '0', (string) $control, '0', 'Actual refund of customer credit'), $l->line($cash->system_key, '0', (string) $amount, '0', (string) $base, 'Actual customer credit refund')] : [$l->line($cash->system_key, (string) $amount, '0', (string) $base, '0', 'Actual supplier claim refund received'), $l->line($key, '0', (string) $amount, '0', (string) $control, 'Supplier credit settled')];
                $this->balance($lines);
            }
            $flow = $flows->create($b, $kind, $data, $actor, [['currency' => $credit->currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $rateId, 'amount' => (string) $amount, 'cash_account_id' => $cash?->id, 'cash_direction' => $direction, 'lines' => $lines]], $details, $guards);
            DB::table('finance_trade_credit_uses')->insert(['business_id' => $b->id, 'trade_credit_id' => $credit->id, 'workflow_id' => $flow->id, 'kind' => $kind, 'commercial_invoice_id' => $doc instanceof CommercialInvoice ? $doc->id : null, 'supplier_bill_id' => $doc instanceof FinanceSupplierBill ? $doc->id : null, 'amount' => (string) $amount, 'control_base' => (string) $control, 'created_at' => now(), 'updated_at' => now()]);

            return $flow;
        }, 3);
    }

    public function validate(FinanceWorkflow $flow): void
    {
        $b = Business::findOrFail($flow->business_id);
        $d = $flow->data_snapshot['details'];
        if ($flow->kind === 'trade_credit') {
            $credit = FinanceTradeCredit::query()->where('business_id', $b->id)->where('workflow_id', $flow->id)->firstOrFail();
            foreach ($d['credit'] as $key => $v) {
                $actual = $key === 'due_on' ? $credit->due_on?->toDateString() : $credit->{$key};
                abort_unless(in_array($key, ['amount', 'tax_amount', 'applied_amount', 'excess_amount', 'excess_base'], true) ? BigDecimal::of((string) $actual)->isEqualTo((string) $v) : (string) $actual === (string) $v, 422, 'Agreed trade credit fields changed.');
            }$case = StockExceptionCase::query()->where('business_id', $b->id)->findOrFail($credit->case_id);
            app(StockExceptionCases::class)->verify($case);
            abort_unless($case->snapshot_hash === $d['case_hash'] && in_array($case->status, ['open', 'investigating', 'awaiting_disposition'], true), 422, 'Resolve this credit before closing or changing its claim case.');
            $state = $this->state($b, $this->document($b, $d['document_type'], $d['document_id']), $flow->id);
            abort_unless($state['hash'] === $d['document_hash'], 422, 'Original document balances or settlement evidence changed. Withdraw and prepare the agreed credit again.');
        } else {
            $credit = FinanceTradeCredit::query()->where('business_id', $b->id)->with('workflow')->findOrFail($d['credit_id']);
            $r = $this->remaining($credit, true, $flow->id);
            $use = DB::table('finance_trade_credit_uses')->where('business_id', $b->id)->where('workflow_id', $flow->id)->first();
            abort_unless($use && $r['hash'] === $d['credit_hash'] && $use->trade_credit_id === $credit->id && BigDecimal::of((string) $use->amount)->isEqualTo((string) $flow->amount) && ! BigDecimal::of((string) $flow->amount)->isGreaterThan($r['amount']), 422, 'Held credit changed or was already consumed.');
            if ($flow->kind === 'trade_credit_apply') {
                $state = $this->state($b, $this->document($b, $d['document_type'], $d['document_id']), $flow->id);
                abort_unless($state['hash'] === $d['document_hash'], 422, 'The target document changed. Reprepare the credit application.');
            }
        }
    }

    public function writeOff(Business $b, StockExceptionCase $case, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($b, $case, $data, $actor) {
            app(OrderOperations::class)->lockForWork($b->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($b, 'supplier_return_loss', $data)) {
                return $old;
            }$case = StockExceptionCase::query()->where('business_id', $b->id)->with('workflow')->findOrFail($case->id);
            app(StockExceptionCases::class)->verify($case);
            abort_unless($case->kind === 'supplier_return' && in_array($case->status, ['awaiting_disposition', 'investigating'], true) && $case->workflow?->posting_status === 'posted', 422, 'Use a posted supplier return with an unresolved recovery.');
            if ($case->resolution_workflow_id) {
                abort_if(FinanceWorkflow::query()->where('business_id', $b->id)->whereKey($case->resolution_workflow_id)->whereIn('posting_status', ['posted', 'not_posted'])->exists(), 422, 'Resolve the existing credit/loss decision first.');
            }$cost = $case->workflow->data_snapshot['details']['value']['base_cost'];
            $l = app(FinanceLedger::class);
            $flow = $flows->create($b, 'supplier_return_loss', $data, $actor, [['currency' => $b->base_currency, 'rate_to_base' => '1', 'amount' => $cost, 'lines' => [$l->line('supplier_return_loss', $cost, '0', $cost, '0', 'Reviewed uncollectible supplier return claim'), $l->line('supplier_return_pending', '0', $cost, '0', $cost, 'Write off returned-goods claim without forgiving the original bill')]]], ['case_id' => $case->id, 'case_hash' => $case->snapshot_hash, 'physical_workflow_id' => $case->workflow_id, 'physical_hash' => $case->workflow->snapshot_hash]);
            $case->update(['resolution_workflow_id' => $flow->id]);

            return $flow;
        }, 3);
    }

    public function validateLoss(FinanceWorkflow $flow): void
    {
        $d = $flow->data_snapshot['details'];
        $case = StockExceptionCase::query()->where('business_id', $flow->business_id)->with('workflow')->findOrFail($d['case_id']);
        app(StockExceptionCases::class)->verify($case);
        abort_unless($case->snapshot_hash === $d['case_hash'] && $case->resolution_workflow_id === $flow->id && in_array($case->status, ['awaiting_disposition', 'investigating'], true) && $case->workflow_id === $d['physical_workflow_id'] && $case->workflow?->posting_status === 'posted' && $case->workflow->snapshot_hash === $d['physical_hash'], 422, 'This supplier claim has changed or already has another resolution.');
    }

    public function assertNoPending(int $businessId, string $field, int $id, ?int $except = null): void
    {
        foreach (['finance_trade_credits', 'finance_trade_credit_uses'] as $table) {
            abort_if(DB::table($table.' as c')->join('finance_workflows as w', 'w.id', '=', 'c.workflow_id')->where('c.business_id', $businessId)->where('w.business_id', $businessId)->where('c.'.$field, $id)->where('w.posting_status', 'not_posted')->when($except, fn ($q) => $q->where('w.id', '!=', $except))->exists(), 422, 'Finish or withdraw the pending return/claim credit for this document first.');
        }
    }

    public function assertNoCredit(int $businessId, string $field, int $id): void
    {
        abort_if(FinanceTradeCredit::query()->where('business_id', $businessId)->where($field, $id)->whereHas('workflow', fn ($q) => $q->whereIn('posting_status', ['posted', 'not_posted']))->exists(), 422, 'This document has a linked return/claim credit. Use its traceable credit balance instead of another full correction.');
    }
}
