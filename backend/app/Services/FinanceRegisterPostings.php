<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\FinanceAccount;
use App\Models\FinanceBankMatch;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinancePartialBankReturn;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceTransactionReversal;
use App\Models\LoanFacility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceRegisterPostings
{
    public function position(BusinessAsset|LoanFacility $record, ?string $through = null): array
    {
        $entries = FinanceRegisterEntry::query()->where('business_id', $record->business_id)->where($record instanceof BusinessAsset ? 'asset_id' : 'loan_id', $record->id)->whereIn('posting_status', ['posted', 'reversed'])->when($through, fn ($q) => $q->whereDate('event_on', '<=', $through))->orderBy('id')->get();
        $lines = FinanceJournalLine::query()->where('finance_journal_lines.business_id', $record->business_id)
            ->join('finance_journals as j', function ($q) {
                $q->on('j.id', '=', 'finance_journal_lines.journal_id')->on('j.business_id', '=', 'finance_journal_lines.business_id');
            })
            ->join('finance_accounts as a', function ($q) {
                $q->on('a.id', '=', 'finance_journal_lines.account_id')->on('a.business_id', '=', 'finance_journal_lines.business_id');
            })
            ->where('j.source_type', 'register_entry')->whereIn('j.source_id', $entries->modelKeys())->when($through, fn ($q) => $q->whereDate('j.posted_on', '<=', $through))->orderBy('finance_journal_lines.id')
            ->get(['finance_journal_lines.*', 'j.source_id', 'j.posted_on', 'a.system_key']);
        $balances = [];
        $fingerprint = [];
        $latest = '0000-00-00';
        foreach ($lines as $line) {
            $key = $line->system_key;
            $balances[$key] ??= ['units' => BigDecimal::zero(), 'base' => BigDecimal::zero()];
            $balances[$key]['units'] = $balances[$key]['units']->plus((string) $line->debit)->minus((string) $line->credit);
            $balances[$key]['base'] = $balances[$key]['base']->plus((string) $line->base_debit)->minus((string) $line->base_credit);
            $day = substr((string) $line->posted_on, 0, 10);
            $latest = max($latest, $day);
            $fingerprint[] = $line->only(['id', 'journal_id', 'source_id', 'posted_on', 'account_id', 'debit', 'credit', 'base_debit', 'base_credit']);
        }
        foreach ($entries as $entry) {
            $fingerprint[] = $entry->only(['id', 'snapshot_hash', 'posting_status']);
        }

        $fingerprint[] = $record instanceof BusinessAsset ? $record->only(['ownership', 'useful_life_months', 'residual_base', 'depreciation_start_on']) : $record->only(['loan_direction', 'lending_suspended', 'currency', 'limit_amount', 'revolving', 'starts_on', 'matures_on', 'lender_kind', 'agreement_reference', 'terms']);

        return ['entries' => $entries, 'balances' => $balances, 'latest' => $latest, 'fingerprint' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR))];
    }

    public function prepare(Business $business, string $kind, array $data, int $actor): FinanceRegisterEntry
    {
        return DB::transaction(function () use ($business, $kind, $data, $actor) {
            $business = app(OrderOperations::class)->lockForWork($business->id, 'finance');
            ksort($data);
            $payloadHash = hash('sha256', json_encode([$kind, $data], JSON_THROW_ON_ERROR));
            $existing = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 422, 'This request reference was already used with different details.');

                return $existing;
            }
            $asset = isset($data['asset_id']) ? BusinessAsset::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['asset_id']) : null;
            $loan = isset($data['loan_id']) ? LoanFacility::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($data['loan_id']) : null;
            abort_unless(($asset !== null) !== ($loan !== null), 422, 'Choose one asset or loan source.');
            $record = $asset ?? $loan;
            $position = $this->position($record);
            abort_if(FinanceRegisterEntry::query()->where('business_id', $business->id)->where($asset ? 'asset_id' : 'loan_id', $record->id)->where('posting_status', 'not_posted')->exists(), 422, 'Complete or reject the pending register transaction first.');
            $date = $data['event_on'];
            abort_unless($date <= now($business->timezone)->toDateString() && $date >= $position['latest'], 422, 'Use an actual event date on or after the latest register journal.');
            app(FinancePeriodControls::class)->assertWritable($business, $date);
            if ($kind === 'register_partial_return') {
                $prepared = $this->partialReturn($business, $record, $position, $data);
            } elseif ($kind === 'register_reversal') {
                $prepared = $this->reversal($business, $record, $position, $data);
            } else {
                $prepared = $asset ? app(AssetRegister::class)->financial($business, $asset, $kind, $data, $position) : app(LoanRegister::class)->financial($business, $loan, $kind, $data, $position);
            }
            $entry = [
                'business_id' => $business->id, 'reference' => 'REG-'.Str::upper((string) Str::ulid()), 'kind' => $kind, 'asset_id' => $asset?->id, 'loan_id' => $loan?->id,
                'instalment_id' => $prepared['instalment_id'] ?? null, 'original_entry_id' => $prepared['original_entry_id'] ?? null,
                'return_bank_line_id' => $prepared['return_bank_line_id'] ?? null, 'event_on' => $date, 'currency' => $prepared['currency'], 'amount' => $prepared['amount'],
                'exchange_rate_id' => $prepared['exchange_rate_id'], 'rate_to_base' => $prepared['rate_to_base'],
                'cash_account_id' => $prepared['cash_account_id'] ?? null, 'cash_direction' => $prepared['cash_direction'] ?? null, 'cash_amount' => $prepared['cash_amount'] ?? '0.00',
                'external_reference' => $data['external_reference'], 'reason' => $data['reason'], 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $payloadHash,
            ];
            $snapshot = ['entry' => $entry, 'state_before' => $position['fingerprint'], 'lines' => $prepared['lines'], 'effects' => $prepared['effects'] ?? [],
                'details' => $prepared['details'] ?? [], 'register_name' => $asset?->name ?? $loan?->lender_name, 'agreement' => $prepared['agreement'] ?? ($asset ? $asset->only(['ownership', 'purchase_reference', 'useful_life_months', 'residual_base', 'depreciation_start_on']) : $loan->only(['reference', 'lender_name', 'currency', 'limit_amount', 'starts_on', 'matures_on', 'agreement_reference', 'terms'])), 'evidence' => []];
            $source = FinanceRegisterEntry::create([...$entry, 'posting_status' => 'not_posted', 'data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))]);

            return $source;
        }, 3);
    }

    public function cash(Business $business, string $currency, array $data): FinanceAccount
    {
        return app(FinanceTreasury::class)->moneyAccount($business, [...$data, 'currency' => $currency, 'account_key' => $data['cash_account_key'] ?? 'cash']);
    }

    public function lines(FinanceRegisterEntry $entry): array
    {
        app(OrderOperations::class)->lockForWork($entry->business_id, 'finance');
        $data = $entry->data_snapshot;
        abort_unless(hash_equals($entry->snapshot_hash, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))), 422, 'The frozen register evidence changed.');
        foreach ($data['entry'] as $key => $value) {
            $same = match ($key) {
                'amount','rate_to_base','cash_amount' => BigDecimal::of((string) $entry->{$key})->isEqualTo((string) $value),
                'event_on' => $entry->event_on->toDateString() === $value,
                default => (string) $entry->{$key} === (string) $value,
            };
            abort_unless($same, 422, 'A frozen register transaction field changed.');
        }
        $record = $entry->asset_id ? BusinessAsset::query()->where('business_id', $entry->business_id)->lockForUpdate()->findOrFail($entry->asset_id) : LoanFacility::query()->where('business_id', $entry->business_id)->lockForUpdate()->findOrFail($entry->loan_id);
        abort_unless($this->position($record)['fingerprint'] === $data['state_before'], 422, 'The register balance changed. Reject and prepare a new transaction from the current position.');
        if ($entry->cash_account_id) {
            $account = FinanceAccount::query()->where('business_id', $entry->business_id)->findOrFail($entry->cash_account_id);
            app(FinanceBankProfiles::class)->assertPaymentAccount($account, $entry->currency);
        }
        if ($entry->kind === 'register_partial_return') {
            $original = FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->findOrFail($entry->original_entry_id);
            abort_unless($original->posting_status === 'posted' && hash_equals($original->snapshot_hash, hash('sha256', json_encode($original->data_snapshot, JSON_THROW_ON_ERROR))) && $original->snapshot_hash === $data['details']['original_hash'], 422, 'The original source changed or was already fully corrected.');
            foreach ($original->data_snapshot['entry'] as $key => $value) {
                $actual = $key === 'event_on' ? $original->event_on->toDateString() : $original->{$key};
                abort_unless(in_array($key, ['amount', 'cash_amount', 'rate_to_base'], true) ? BigDecimal::of((string) $actual)->isEqualTo((string) $value) : (string) $actual === (string) $value, 422, 'The original immutable register fields changed.');
            }
        }
        if ($entry->return_bank_line_id) {
            abort_unless($this->assertReturn($entry) === ($data['details']['bank_return'] ?? null), 422, 'The bank-return evidence changed after preparation.');
        }
        foreach ($data['evidence'] ?? [] as $file) {
            $disk = Storage::disk('local');
            abort_unless(str_starts_with($file['path'], 'register-evidence/'.$entry->business_id.'/') && $disk->exists($file['path']) && hash_equals($file['sha256'], hash_file('sha256', $disk->path($file['path']))), 422, 'Register supporting evidence is missing or changed.');
        }

        return $data['lines'];
    }

    public function finalize(FinanceRegisterEntry $entry): void
    {
        $entry->update(['posting_status' => 'posted', 'posted_at' => now()]);
        if ($entry->kind === 'loan_writeoff' && $entry->loan_id) {
            LoanFacility::query()->where('business_id', $entry->business_id)->whereKey($entry->loan_id)->where('loan_direction', 'lend')->update(['lending_suspended' => true]);
        }
        if ($entry->kind === 'register_reversal' && $entry->loan_id && ($entry->data_snapshot['details']['kind'] ?? null) === 'loan_writeoff') {
            $original = FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->findOrFail($entry->original_entry_id);
            LoanFacility::query()->where('business_id', $entry->business_id)->whereKey($entry->loan_id)->where('loan_direction', 'lend')->update(['lending_suspended' => (bool) ($original->data_snapshot['details']['lending_suspension_before'] ?? false)]);
        }
        if ($entry->kind === 'register_partial_return') {
            $total = BigDecimal::of($entry->data_snapshot['details']['original_cash_total']);
            $used = BigDecimal::zero();
            foreach (FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->where('original_entry_id', $entry->original_entry_id)->where('kind', 'register_partial_return')->where('posting_status', 'posted')->pluck('cash_amount') as $amount) {
                $used = $used->plus((string) $amount);
            }if ($used->isEqualTo($total) && ($entry->data_snapshot['details']['kind'] ?? null) !== 'asset_disposal') {
                FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->whereKey($entry->original_entry_id)->update(['posting_status' => 'reversed']);
            }
        }
        if ($entry->original_entry_id && $entry->kind === 'register_reversal') {
            FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->whereKey($entry->original_entry_id)->update(['posting_status' => 'reversed']);
        }
    }

    private function reversal(Business $business, BusinessAsset|LoanFacility $record, array $position, array $data): array
    {
        $original = FinanceRegisterEntry::query()->where('business_id', $business->id)->where($record instanceof BusinessAsset ? 'asset_id' : 'loan_id', $record->id)->where('posting_status', 'posted')->findOrFail($data['original_entry_id'] ?? 0);
        abort_unless($position['entries']->where('posting_status', 'posted')->where('kind', '!=', 'register_reversal')->last()?->id === $original->id && $original->kind !== 'register_reversal', 422, 'Only the latest unreversed register journal can be corrected. Later effects must be resolved first.');
        abort_if(FinanceRegisterEntry::query()->where('business_id', $business->id)->where('original_entry_id', $original->id)->whereIn('posting_status', ['not_posted', 'posted'])->exists(), 422, 'A correction already exists.');
        abort_if(FinanceRegisterEntry::query()->where('business_id', $business->id)->where('original_entry_id', $original->id)->where('kind', 'register_partial_return')->whereIn('posting_status', ['not_posted', 'posted'])->exists(), 422, 'Use the remaining partial-return balance; the whole original journal has already been partly corrected.');
        $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'register_entry')->where('source_id', $original->id)->with('lines.account')->firstOrFail();
        $lines = [];
        foreach ($journal->lines as $line) {
            $lines[] = app(FinanceLedger::class)->line($line->account->system_key, (string) $line->credit, (string) $line->debit, (string) $line->base_credit, (string) $line->base_debit, 'Correction of '.$original->reference);
        }
        $effects = [];
        foreach ($original->data_snapshot['effects'] as $key => $value) {
            $effects[$key] = (string) BigDecimal::of((string) $value)->negated();
        }
        $prepared = ['currency' => $original->currency, 'amount' => (string) $original->amount, 'exchange_rate_id' => $original->exchange_rate_id, 'rate_to_base' => (string) $original->rate_to_base, 'cash_account_id' => $original->cash_account_id, 'cash_direction' => $original->cash_direction === null ? null : ($original->cash_direction === 'in' ? 'out' : 'in'), 'cash_amount' => (string) $original->cash_amount, 'original_entry_id' => $original->id, 'lines' => $lines, 'effects' => $effects, 'details' => ['corrects' => $original->reference, 'kind' => $original->kind]];
        if ($original->cash_account_id) {
            $cash = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($original->cash_account_id);
            if ($cash->system_key === 'cash' || str_starts_with($cash->system_key, 'cash_')) {
                abort_unless(mb_strlen(trim($data['cash_witness'] ?? '')) >= 10, 422, 'Record the custodian/witness and signed actual cash-return voucher.');
                $prepared['details']['cash_witness'] = $data['cash_witness'];
                if ($prepared['cash_direction'] === 'out') {
                    abort_unless(! app(FinanceWorkflows::class)->accountBasis($business->id, $cash->id, $data['event_on'])['units']->isLessThan((string) $original->cash_amount), 422, 'The original cash receipt is not available for actual return.');
                }

                return $prepared;
            }
            abort_unless(! empty($data['return_bank_line_id']), 422, 'Cash corrections require the matching actual bank return line, not only an editing reason.');
            $prepared['return_bank_line_id'] = (int) $data['return_bank_line_id'];
            $temporary = new FinanceRegisterEntry([...$prepared, 'business_id' => $business->id, 'event_on' => $data['event_on']]);
            $prepared['details']['bank_return'] = $this->assertReturn($temporary);
        }

        return $prepared;
    }

    private function partialReturn(Business $business, BusinessAsset|LoanFacility $record, array $position, array $data): array
    {
        $original = FinanceRegisterEntry::query()->where('business_id', $business->id)->where($record instanceof BusinessAsset ? 'asset_id' : 'loan_id', $record->id)->where('posting_status', 'posted')->findOrFail($data['original_entry_id'] ?? 0);
        abort_unless(in_array($original->kind, ['asset_purchase', 'asset_payment', 'asset_disposal', 'loan_drawdown', 'loan_repayment'], true) && $original->cash_account_id && $position['entries']->where('posting_status', 'posted')->whereNotIn('kind', ['register_reversal', 'register_partial_return'])->last()?->id === $original->id, 422, 'Use the latest eligible cash acquisition, equipment payment, disposal proceeds, drawdown or repayment. Resolve later financial effects first.');
        $math = app(FinanceMath::class);
        $total = BigDecimal::of((string) $original->cash_amount);
        $used = BigDecimal::zero();
        foreach (FinanceRegisterEntry::query()->where('business_id', $business->id)->where('original_entry_id', $original->id)->where('kind', 'register_partial_return')->whereIn('posting_status', ['posted', 'not_posted'])->get() as $prior) {
            $used = $used->plus((string) $prior->cash_amount);
        }
        $amount = $math->money($data['amount'] ?? '0');
        abort_unless($amount->isGreaterThan(0) && ! $used->plus($amount)->isGreaterThan($total), 422, 'The return exceeds the uncorrected original cash movement.');
        $portion = fn (string $value, BigDecimal $quantity) => $quantity->isEqualTo($total) ? BigDecimal::of($value) : $math->money(BigDecimal::of($value)->multipliedBy($quantity)->dividedBy($total, 14, RoundingMode::HalfUp));
        $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'register_entry')->where('source_id', $original->id)->with('lines.account')->firstOrFail();
        $lines = [];
        foreach ($journal->lines as $row) {
            $v = [];
            foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $key) {
                $v[$key] = $portion((string) $row->{$key}, $used->plus($amount))->minus($portion((string) $row->{$key}, $used));
            }if (collect($v)->every(fn ($value) => $value->isZero())) {
                continue;
            }$lines[] = app(FinanceLedger::class)->line($row->account->system_key, (string) $v['credit'], (string) $v['debit'], (string) $v['base_credit'], (string) $v['base_debit'], 'Partial actual return of '.$original->reference);
        }
        $effects = [];
        foreach ($original->data_snapshot['effects'] as $key => $value) {
            $effects[$key] = (string) $portion((string) $value, $used->plus($amount))->minus($portion((string) $value, $used))->negated();
        }
        $net = BigDecimal::zero();
        foreach ($lines as $line) {
            $net = $net->plus($line['base_debit'])->minus($line['base_credit']);
        }if (! $net->isZero()) {
            $lines[] = app(FinanceLedger::class)->line($net->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $net->isLessThan(0) ? (string) $net->abs() : '0', $net->isGreaterThan(0) ? (string) $net : '0', 'Proportional return rounding');
        }
        $unitNet = BigDecimal::zero();
        foreach ($lines as $line) {
            $unitNet = $unitNet->plus($line['debit'])->minus($line['credit']);
        }if (! $unitNet->isZero()) {
            $lines[] = app(FinanceLedger::class)->line($unitNet->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', $unitNet->isLessThan(0) ? (string) $unitNet->abs() : '0', $unitNet->isGreaterThan(0) ? (string) $unitNet : '0', '0', '0', 'Original-currency rounding on partial source return');
        }
        $componentReturns = [];
        foreach (['principal', 'interest', 'fees'] as $component) {
            $value = (string) ($original->data_snapshot['details'][$component] ?? '0');
            $componentReturns[$component] = (string) $portion($value, $used->plus($amount))->minus($portion($value, $used));
        }
        if ($original->kind === 'asset_disposal') {
            $cashLine = $journal->lines->firstWhere('account_id', $original->cash_account_id);
            $base = $portion((string) $cashLine->base_debit, $used->plus($amount))->minus($portion((string) $cashLine->base_debit, $used));
            $lines = [app(FinanceLedger::class)->line('asset_disposal_loss', (string) $amount, '0', (string) $base, '0', 'Agreed reduction/refund of disposal proceeds; asset remains disposed'), app(FinanceLedger::class)->line($cashLine->account->system_key, '0', (string) $amount, '0', (string) $base, 'Actual disposal proceeds refunded')];
            $effects = [];
        }
        $prepared = ['instalment_id' => $original->instalment_id, 'currency' => $original->currency, 'amount' => (string) $amount, 'exchange_rate_id' => $original->exchange_rate_id, 'rate_to_base' => (string) $original->rate_to_base, 'cash_account_id' => $original->cash_account_id, 'cash_direction' => $original->cash_direction === 'in' ? 'out' : 'in', 'cash_amount' => (string) $amount, 'original_entry_id' => $original->id, 'lines' => $lines, 'effects' => $effects, 'details' => ['kind' => $original->kind, 'original_hash' => $original->snapshot_hash, 'corrects' => $original->reference, 'returned_before' => (string) $used, 'original_cash_total' => (string) $total, 'component_returns' => $componentReturns]];
        $cash = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($original->cash_account_id);
        if ($cash->system_key === 'cash' || str_starts_with($cash->system_key, 'cash_')) {
            abort_unless(mb_strlen(trim($data['cash_witness'] ?? '')) >= 10, 422, 'Record the actual signed cash-return voucher and an independent custodian/witness.');
            $prepared['details']['cash_witness'] = $data['cash_witness'];
        } else {
            abort_unless(! empty($data['return_bank_line_id']), 422, 'Choose the exact actual imported partial bank return.');
            $prepared['return_bank_line_id'] = (int) $data['return_bank_line_id'];
            $temp = new FinanceRegisterEntry([...$prepared, 'business_id' => $business->id, 'event_on' => $data['event_on']]);
            $prepared['details']['bank_return'] = $this->assertReturn($temp);
        }
        if ($prepared['cash_direction'] === 'out') {
            abort_unless(! app(FinanceWorkflows::class)->accountBasis($business->id, $cash->id, $data['event_on'])['units']->isLessThan($amount), 422, 'The cash/bank account does not hold enough money for this actual return.');
        }

        return $prepared;
    }

    private function assertReturn(FinanceRegisterEntry $entry): array
    {
        $line = FinanceBankStatementLine::query()->where('business_id', $entry->business_id)->with('statement')->lockForUpdate()->findOrFail($entry->return_bank_line_id);
        abort_unless($line->statement->status === 'open', 422, 'Use an actual return from an open bank statement.');
        abort_unless($line->statement->account_id === $entry->cash_account_id && $line->statement->currency === $entry->currency && $line->transaction_on->toDateString() === $entry->event_on->toDateString() && $line->direction === ($entry->cash_direction === 'in' ? 'credit' : 'debit') && BigDecimal::of((string) $line->amount)->isEqualTo((string) $entry->cash_amount), 422, 'The bank return must match the exact account, currency, amount, direction and actual event date.');
        abort_if(app(FinanceMoneyCorrections::class)->bankReservation($entry->business_id, $line->id), 422, 'This bank line is reserved for a documented money correction.');
        abort_if(FinanceBankMatch::query()->where('business_id', $entry->business_id)->where('statement_line_id', $line->id)->where('status', '!=', 'rejected')->exists(), 422, 'This bank line already has a reconciliation match.');
        abort_if(FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->where('return_bank_line_id', $line->id)->when($entry->exists, fn ($q) => $q->whereKeyNot($entry->id))->whereIn('posting_status', ['not_posted', 'posted', 'reversed'])->exists(), 422, 'This return line is reserved by another correction.');
        foreach ([FinanceTransactionReversal::class, FinancePartialBankReturn::class] as $model) {
            abort_if($model::query()->where('business_id', $entry->business_id)->where('return_bank_line_id', $line->id)->exists(), 422, 'This bank line belongs to another bank-return workflow.');
        }

        return ['line_id' => $line->id, 'statement_id' => $line->statement_id, 'account_id' => $line->statement->account_id, 'currency' => $line->statement->currency, 'file_sha256' => $line->statement->file_sha256, 'transaction_on' => $line->transaction_on->toDateString(), 'direction' => $line->direction, 'amount' => (string) $line->amount, 'bank_reference' => $line->bank_reference, 'description' => $line->description];
    }

    public function cancel(FinanceRegisterEntry $entry, int $actor, string $reason): void
    {
        DB::transaction(function () use ($entry, $actor, $reason) {
            app(OrderOperations::class)->lockForWork($entry->business_id, 'finance');
            $entry = FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->lockForUpdate()->findOrFail($entry->id);
            abort_unless($entry->posting_status === 'not_posted' && $entry->created_by === $actor, 422, 'Only the preparer can withdraw an unsubmitted transaction.');
            abort_if(FinancePostingRequest::query()->where('business_id', $entry->business_id)->where('source_type', 'register_entry')->where('source_id', $entry->id)->exists(), 422, 'Withdraw or reject the finance review instead.');
            $entry->update(['posting_status' => 'rejected']);
            app(PlatformAccess::class)->audit($entry->business_id, $actor, 'register.draft_withdrawn', $reason, [], ['entry_id' => $entry->id]);
        }, 3);
    }

    public function attach(FinanceRegisterEntry $entry, UploadedFile $file, int $actor): void
    {
        $path = $file->store('register-evidence/'.$entry->business_id, 'local');
        try {
            DB::transaction(function () use ($entry, $path, $file, $actor) {
                app(OrderOperations::class)->lockForWork($entry->business_id, 'finance');
                $entry = FinanceRegisterEntry::query()->where('business_id', $entry->business_id)->lockForUpdate()->findOrFail($entry->id);
                abort_unless($entry->posting_status === 'not_posted' && ! FinancePostingRequest::query()->where('business_id', $entry->business_id)->where('source_type', 'register_entry')->where('source_id', $entry->id)->exists(), 422, 'Evidence is locked when finance review starts.');
                $snapshot = $entry->data_snapshot;
                abort_unless(count($snapshot['evidence']) < 5, 422, 'Attach up to five supporting files.');
                $snapshot['evidence'][] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 150), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor, 'uploaded_at' => now()->toIso8601String()];
                $entry->update(['data_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }
}
