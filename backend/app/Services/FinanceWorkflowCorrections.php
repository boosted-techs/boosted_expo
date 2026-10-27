<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class FinanceWorkflowCorrections
{
    public const KINDS = ['owner_contribution', 'owner_drawing', 'cash_topup', 'exchange', 'trade_credit_settlement', 'trade_credit_apply', 'opening_item_settlement', 'opening_item_apply', 'opening_item_adjustment'];

    public function corrected(int $business, int $workflow, bool $pending = false): bool
    {
        return DB::table('finance_workflow_corrections as c')->join('finance_workflows as w', 'w.id', '=', 'c.workflow_id')->where('c.business_id', $business)->where('w.business_id', $business)->where('c.original_workflow_id', $workflow)->whereIn('w.posting_status', $pending ? ['posted', 'not_posted'] : ['posted'])->exists();
    }

    public function prepare(Business $business, FinanceWorkflow $original, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($business, $original, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            $data['original_workflow_id'] = $original->id;
            if ($old = $flows->existing($business, 'workflow_correction', $data)) {
                return $old;
            }
            $original = FinanceWorkflow::query()->where('business_id', $business->id)->where('posting_status', 'posted')->with('legs')->findOrFail($original->id);
            abort_unless(in_array($original->kind, self::KINDS, true) && ! $this->corrected($business->id, $original->id, true), 422, 'This source is not eligible for another complete correction.');
            abort_unless(hash_equals($original->snapshot_hash, $flows->hash($original->data_snapshot)), 422, 'Original event evidence changed.');
            $legs = [];
            $proof = [];
            $guards = [];
            foreach ($original->legs as $leg) {
                $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'workflow_leg')->where('source_id', $leg->id)->with('lines.account')->firstOrFail();
                abort_unless($data['event_on'] >= $journal->posted_on->toDateString(), 422, 'An actual correction cannot precede its original event.');
                $lines = [];
                foreach ($journal->lines as $line) {
                    $account = $line->account;
                    $lines[] = app(FinanceLedger::class)->line($account->system_key, (string) $line->credit, (string) $line->debit, (string) $line->base_credit, (string) $line->base_debit, 'Complete documented correction of '.$original->reference);
                    if ($account->type === 'asset' && (in_array($account->system_key, ['cash', 'bank'], true) || str_starts_with($account->system_key, 'bank_') || str_starts_with($account->system_key, 'cash_'))) {
                        $basis = $flows->accountBasis($business->id, $account->id, $data['event_on']);
                        if (BigDecimal::of((string) $line->debit)->isGreaterThan(0)) {
                            abort_unless(! $basis['units']->isLessThan((string) $line->debit), 422, 'The original receiving account no longer has enough money for the actual return.');
                        }
                        $guards[] = ['type' => 'account', 'id' => $account->id, 'fingerprint' => $basis['fingerprint']];
                        if ($account->system_key === 'cash' || str_starts_with($account->system_key, 'cash_')) {
                            abort_unless(mb_strlen(trim($data['cash_witness'] ?? '')) >= 10, 422, 'Record the signed cash-return voucher and independent custodian/witness.');
                        }
                    }
                }
                $legs[] = ['currency' => $leg->currency, 'rate_to_base' => (string) $leg->rate_to_base, 'exchange_rate_id' => $leg->exchange_rate_id, 'amount' => (string) $leg->amount, 'cash_account_id' => $leg->cash_account_id, 'cash_direction' => $leg->cash_direction === 'in' ? 'out' : ($leg->cash_direction === 'out' ? 'in' : null), 'lines' => $lines];
                $proof[] = ['original_leg_id' => $leg->id, 'journal_id' => $journal->id, 'journal_hash' => $flows->hash(app(FinanceManagementReports::class)->journalEvidence($journal))];
            }
            $flow = $flows->create($business, 'workflow_correction', $data, $actor, $legs, ['original_workflow_id' => $original->id, 'original_hash' => $original->snapshot_hash, 'sources' => $proof], $guards);
            DB::table('finance_workflow_corrections')->insert(['business_id' => $business->id, 'original_workflow_id' => $original->id, 'workflow_id' => $flow->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($flow->legs()->get() as $index => $leg) {
                if ($leg->cash_account_id) {
                    $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($leg->cash_account_id);
                    if ($account->system_key !== 'cash' && ! str_starts_with($account->system_key, 'cash_')) {
                        abort_unless(! empty($data['return_bank_lines'][$index]), 422, 'Import the actual opposite bank movement for each bank leg, including returned fees where applicable.');
                        app(FinanceMoneyCorrections::class)->bindBank($flow, $leg->id, (int) $data['return_bank_lines'][$index]);
                    }
                }
            }

            return $flow;
        }, 3);
    }

    public function validate(FinanceWorkflow $flow): void
    {
        $d = $flow->data_snapshot['details'];
        $original = FinanceWorkflow::query()->where('business_id', $flow->business_id)->findOrFail($d['original_workflow_id']);
        abort_unless($original->posting_status === 'posted' && $original->snapshot_hash === $d['original_hash'], 422, 'The original event changed.');
        abort_if(DB::table('finance_workflow_corrections as c')->join('finance_workflows as w', 'w.id', '=', 'c.workflow_id')->where('c.business_id', $flow->business_id)->where('c.original_workflow_id', $original->id)->where('c.workflow_id', '!=', $flow->id)->whereIn('w.posting_status', ['posted', 'not_posted'])->exists(), 422, 'Another correction already uses this event.');
        foreach ($d['sources'] as $source) {
            $journal = FinanceJournal::query()->where('business_id', $flow->business_id)->findOrFail($source['journal_id']);
            abort_unless(hash_equals($source['journal_hash'], app(FinanceWorkflows::class)->hash(app(FinanceManagementReports::class)->journalEvidence($journal))), 422, 'Original posting evidence changed.');
        }
        app(FinanceMoneyCorrections::class)->validateBank($flow);
    }
}
