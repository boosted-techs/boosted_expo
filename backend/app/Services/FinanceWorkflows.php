<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClose;
use App\Models\FinanceAccount;
use App\Models\FinanceCutover;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceOperation;
use App\Models\FinancePostingRequest;
use App\Models\FinanceWorkflow;
use App\Models\FinanceWorkflowLeg;
use App\Models\StockExceptionCase;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceWorkflows
{
    public function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function existing(Business $business, string $kind, array $data): ?FinanceWorkflow
    {
        ksort($data);
        $hash = $this->hash([$kind, $data]);
        $row = FinanceWorkflow::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
        if ($row) {
            abort_unless(hash_equals($row->payload_hash, $hash), 422, 'This submission key already has different details.');
        }

        return $row;
    }

    public function create(Business $business, string $kind, array $data, int $actor, array $legs, array $details = [], array $guards = []): FinanceWorkflow
    {
        return DB::transaction(function () use ($business, $kind, $data, $actor, $legs, $details, $guards) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            if ($old = $this->existing($business, $kind, $data)) {
                return $old;
            }
            abort_unless(count($legs) > 0 && count($legs) <= ($kind === 'cutover_opening' ? 1000 : 100), 422, 'Choose at least one valid financial event.');
            app(FinancePeriodControls::class)->assertWritable($business, $data['event_on']);
            abort_unless($data['event_on'] <= now($business->timezone)->toDateString(), 422, 'Actual financial events cannot be future dated.');
            ksort($data);
            $first = $legs[0];
            $fields = ['business_id' => $business->id, 'reference' => 'FIN-'.Str::upper((string) Str::ulid()), 'kind' => $kind, 'event_on' => $data['event_on'], 'currency' => $first['currency'], 'amount' => $first['amount'], 'rate_to_base' => $first['rate_to_base'], 'exchange_rate_id' => $first['exchange_rate_id'] ?? null, 'reason' => $data['reason'], 'external_reference' => $data['external_reference'], 'request_key' => $data['request_key'], 'payload_hash' => $this->hash([$kind, $data]), 'created_by' => $actor];
            $row = FinanceWorkflow::create([...$fields, 'posting_status' => 'not_posted', 'data_snapshot' => [], 'snapshot_hash' => str_repeat('0', 64)]);
            $frozen = [];
            foreach ($legs as $sequence => $leg) {
                $legDate = $leg['event_on'] ?? $data['event_on'];
                abort_unless($legDate >= $data['event_on'] && $legDate <= now($business->timezone)->toDateString(), 422, 'Use an actual valuation date on or after the source event.');
                app(FinancePeriodControls::class)->assertWritable($business, $legDate);
                $leg = FinanceWorkflowLeg::create([...$leg, 'business_id' => $business->id, 'workflow_id' => $row->id, 'sequence' => $sequence, 'event_on' => $legDate, 'posting_status' => 'not_posted']);
                $frozen[] = $leg->only(['id', 'business_id', 'workflow_id', 'sequence', 'event_on', 'currency', 'rate_to_base', 'exchange_rate_id', 'cash_account_id', 'cash_direction', 'amount', 'lines']);
            }
            $snapshot = ['entry' => $fields, 'legs' => $frozen, 'details' => $details, 'guards' => $guards, 'evidence' => []];
            $row->update(['data_snapshot' => $snapshot, 'snapshot_hash' => $this->hash($snapshot)]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'finance.workflow_prepared', $data['reason'], [], ['workflow_id' => $row->id, 'kind' => $kind]);

            return $row;
        }, 3);
    }

    public function accountBasis(int $businessId, int $accountId, string $date, bool $allowHistorical = false, ?int $excludeWorkflow = null): array
    {
        FinanceAccount::query()->where('business_id', $businessId)->where('active', true)->findOrFail($accountId);
        $units = BigDecimal::zero();
        $base = BigDecimal::zero();
        $rows = [];
        foreach (FinanceJournalLine::query()->where('finance_journal_lines.business_id', $businessId)->where('account_id', $accountId)->join('finance_journals as j', function ($q) {
            $q->on('j.id', '=', 'finance_journal_lines.journal_id')->on('j.business_id', '=', 'finance_journal_lines.business_id');
        })->when($excludeWorkflow, fn ($q) => $q->where(fn ($q) => $q->where('j.source_type', '!=', 'workflow_leg')->orWhereNotIn('j.source_id', FinanceWorkflowLeg::query()->where('business_id', $businessId)->where('workflow_id', $excludeWorkflow)->select('id'))))->when($allowHistorical, fn ($q) => $q->whereDate('j.posted_on', '<=', $date))->orderBy('finance_journal_lines.id')->get(['finance_journal_lines.id', 'debit', 'credit', 'base_debit', 'base_credit', 'j.posted_on', 'j.currency']) as $line) {
            abort_unless(substr((string) $line->posted_on, 0, 10) <= $date, 422, 'Use an event date on or after the latest money-account journal.');
            $units = $units->plus((string) $line->debit)->minus((string) $line->credit);
            $base = $base->plus((string) $line->base_debit)->minus((string) $line->base_credit);
            $rows[] = $line->toArray();
        }

        return ['units' => $units, 'base' => $base, 'fingerprint' => $this->hash($rows)];
    }

    public function validate(FinanceWorkflow $row): array
    {
        app(OrderOperations::class)->lockForWork($row->business_id, 'finance');
        $s = $row->data_snapshot;
        abort_unless(hash_equals($row->snapshot_hash, $this->hash($s)), 422, 'Financial evidence changed after preparation.');
        foreach ($s['entry'] as $key => $value) {
            $actual = $key === 'event_on' ? $row->event_on->toDateString() : $row->{$key};
            $same = in_array($key, ['amount', 'rate_to_base'], true) ? BigDecimal::of((string) $actual)->isEqualTo((string) $value) : (string) $actual === (string) $value;
            abort_unless($same, 422, 'A frozen financial event changed.');
        }
        $legs = $row->legs()->get();
        abort_unless($legs->count() === count($s['legs']), 422, 'The prepared accounting legs changed.');
        foreach ($legs as $i => $leg) {
            $actual = $leg->only(array_keys($s['legs'][$i]));
            $actual['event_on'] = $leg->event_on->toDateString();
            $expected = $s['legs'][$i];
            $expected['event_on'] = substr((string) $expected['event_on'], 0, 10);
            foreach (['amount', 'rate_to_base'] as $key) {
                $actual[$key] = (string) BigDecimal::of((string) $actual[$key])->toScale(10);
                $expected[$key] = (string) BigDecimal::of((string) $expected[$key])->toScale(10);
            }abort_unless($actual === $expected, 422, 'A prepared accounting leg changed.');
            if ($leg->cash_account_id) {
                $account = FinanceAccount::query()->where('business_id', $row->business_id)->findOrFail($leg->cash_account_id);
                app(FinanceTreasury::class)->assertAccount($account, $leg->currency);
            }
        }
        foreach ($s['guards'] as $guard) {
            if ($guard['type'] === 'account') {
                abort_unless($this->accountBasis($row->business_id, $guard['id'], $row->event_on->toDateString())['fingerprint'] === $guard['fingerprint'], 422, 'Money-account balances changed. Withdraw and prepare the event again.');
            } elseif ($guard['type'] === 'operation') {
                $source = FinanceOperation::query()->where('business_id', $row->business_id)->findOrFail($guard['id']);
                abort_unless($source->posting_status === 'posted' && $source->snapshot_hash === $guard['snapshot_hash'], 422, 'The original money source changed.');
            }
        }
        if ($row->kind === 'cash_variance') {
            $count = CashClose::query()->where('business_id', $row->business_id)->findOrFail($s['details']['cash_close_id']);
            app(FinanceTreasury::class)->verifyCount($count);
            abort_unless($count->status === 'variance_pending' && $count->workflow_id === $row->id && $count->snapshot_hash === ($s['details']['count_snapshot_hash'] ?? null), 422, 'The reviewed count or variance changed.');
        }
        if ($row->kind === 'cutover_opening') {
            app(FinanceCutovers::class)->validate($row);
        }
        if (in_array($row->kind, ['opening_item_settlement', 'opening_item_apply', 'opening_item_adjustment', 'opening_item_revaluation'], true)) {
            app(FinanceOpeningItems::class)->validate($row);
        }
        if ($row->kind === 'workflow_correction') {
            app(FinanceWorkflowCorrections::class)->validate($row);
        }
        if ($row->kind === 'money_correction') {
            app(FinanceMoneyCorrections::class)->validate($row);
        }
        if ($row->kind === 'goods_return') {
            app(StockExceptionCases::class)->validateReturn($row);
        }
        if ($row->kind === 'supplier_return_loss') {
            app(FinanceTradeCredits::class)->validateLoss($row);
        }
        if (in_array($row->kind, ['trade_credit', 'trade_credit_settlement', 'trade_credit_apply'], true)) {
            app(FinanceTradeCredits::class)->validate($row);
        }
        if (in_array($row->kind, FinanceProductionCosts::KINDS, true)) {
            app(FinanceProductionCosts::class)->validate($row);
        }
        foreach ($s['evidence'] as $file) {
            $disk = Storage::disk('local');
            abort_unless(str_starts_with($file['path'], 'workflow-evidence/'.$row->business_id.'/') && $disk->exists($file['path']) && hash_equals($file['sha256'], hash_file('sha256', $disk->path($file['path']))), 422, 'Private supporting evidence is missing or changed.');
        }

        return $legs->first()->lines;
    }

    public function post(FinanceWorkflow $row, int $actor, int $reviewer, ?array $cashSnapshot = null): FinanceJournal
    {
        $this->validate($row);
        $business = Business::findOrFail($row->business_id);
        $first = null;
        foreach ($row->legs()->get() as $leg) {
            $journal = app(FinanceLedger::class)->record($business, 'workflow_leg', $leg->id, $leg->currency, (string) $leg->rate_to_base, $leg->event_on->toDateString(), $row->reference.' · '.str_replace('_', ' ', $row->kind), $actor, $reviewer, $leg->lines, $cashSnapshot['sources']['workflow_leg:'.$leg->id] ?? null);
            $first ??= $journal;
            $leg->update(['posting_status' => 'posted']);
        }
        if (in_array($row->kind, FinanceProductionCosts::KINDS, true)) {
            app(FinanceProductionCosts::class)->finalize($row);
        }
        if ($row->kind === 'cutover_opening') {
            app(FinanceCutovers::class)->finalize($row, $actor);
        }
        if ($row->kind === 'goods_return') {
            app(StockExceptionCases::class)->finalizeReturn($row, $actor);
        }
        $row->update(['posting_status' => 'posted', 'posted_at' => now()]);
        if ($row->kind === 'trade_credit') {
            $case = StockExceptionCase::query()->where('business_id', $row->business_id)->findOrFail($row->data_snapshot['details']['credit']['case_id']);
            app(StockExceptionCases::class)->event($case, 'credit_posted', 'Agreed credit posted as '.$row->reference.'; its excess is separately available for refund or application.', $actor);
        }
        if ($row->kind === 'cash_variance') {
            CashClose::query()->where('business_id', $row->business_id)->where('workflow_id', $row->id)->update(['status' => 'closed']);
        }
        app(PlatformAccess::class)->audit($row->business_id, $actor, 'finance.workflow_posted', $row->reason, [], ['workflow_id' => $row->id, 'reviewed_by' => $reviewer]);

        return $first;
    }

    public function attach(FinanceWorkflow $row, UploadedFile $file, int $actor): void
    {
        $path = $file->store('workflow-evidence/'.$row->business_id, 'local');
        try {
            DB::transaction(function () use ($row, $file, $path, $actor) {
                app(OrderOperations::class)->lockForWork($row->business_id, 'finance');
                $row = FinanceWorkflow::query()->where('business_id', $row->business_id)->lockForUpdate()->findOrFail($row->id);
                abort_unless($row->posting_status === 'not_posted' && ! FinancePostingRequest::query()->where('business_id', $row->business_id)->where('source_type', 'finance_workflow')->where('source_id', $row->id)->exists(), 422, 'Supporting evidence is frozen when review starts.');
                $s = $row->data_snapshot;
                abort_unless(count($s['evidence']) < 5, 422, 'Attach up to five original documents.');
                $s['evidence'][] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 150), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor];
                $row->update(['data_snapshot' => $s, 'snapshot_hash' => $this->hash($s)]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function reject(FinanceWorkflow $row): void
    {
        $row->update(['posting_status' => 'rejected']);
        if ($row->kind === 'cutover_opening') {
            FinanceCutover::query()->where('business_id', $row->business_id)->where('workflow_id', $row->id)->update(['status' => 'rejected']);
        }
        $row->legs()->update(['posting_status' => 'rejected']);
        if ($row->kind === 'goods_return') {
            StockExceptionCase::query()->where('business_id', $row->business_id)->where('workflow_id', $row->id)->where('status', 'finance_pending')->update(['status' => 'rejected']);
        }
        CashClose::query()->where('business_id', $row->business_id)->where('workflow_id', $row->id)->where('status', 'variance_pending')->update(['status' => 'rejected']);
    }

    public function cancel(FinanceWorkflow $row, int $actor, string $reason): void
    {
        DB::transaction(function () use ($row, $actor, $reason) {
            app(OrderOperations::class)->lockForWork($row->business_id, 'finance');
            $row = FinanceWorkflow::query()->where('business_id', $row->business_id)->lockForUpdate()->findOrFail($row->id);
            abort_unless($row->posting_status === 'not_posted' && $row->created_by === $actor, 422, 'Only the preparer may withdraw an unsubmitted event.');
            abort_if(FinancePostingRequest::query()->where('business_id', $row->business_id)->where('source_type', 'finance_workflow')->where('source_id', $row->id)->exists(), 422, 'Withdraw or reject the existing review first.');
            $this->reject($row);
            app(PlatformAccess::class)->audit($row->business_id, $actor, 'finance.workflow_withdrawn', $reason, [], ['workflow_id' => $row->id]);
        }, 3);
    }
}
