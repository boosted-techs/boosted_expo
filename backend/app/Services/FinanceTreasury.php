<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClose;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinancePayrollSettlement;
use App\Models\FinanceRegisterEntry;
use App\Models\FinanceShipmentCost;
use App\Models\FinanceWorkflow;
use App\Models\FinanceWorkflowLeg;
use App\Models\RecurringObligation;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Support\BusinessCurrencies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceTreasury
{
    public const KINDS = ['exchange' => 'Exchange currencies', 'owner_contribution' => 'Record owner capital', 'owner_drawing' => 'Record owner drawings', 'cash_topup' => 'Top up a cash float'];

    public function assertAccount(FinanceAccount $account, string $currency): void
    {
        if (str_starts_with($account->system_key, 'cash_')) {
            abort_unless($account->active && CashCustody::query()->where('business_id', $account->business_id)->where('account_id', $account->id)->where('currency', $currency)->where('active', true)->exists(), 422, 'Choose an active cash custody in this currency.');

            return;
        }
        app(FinanceBankProfiles::class)->assertPaymentAccount($account, $currency);
    }

    public function moneyAccount(Business $business, array $data, string $prefix = '', bool $allowLegacy = false): FinanceAccount
    {
        $currency = $data[$prefix.'currency'];
        abort_unless(empty($data[$prefix.'bank_profile_id']) || empty($data[$prefix.'cash_custody_id']), 422, 'Choose one bank account or cash float.');
        if (! empty($data[$prefix.'cash_custody_id'])) {
            $c = CashCustody::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data[$prefix.'cash_custody_id']);
            abort_unless($c->currency === $currency, 422, 'Cash custody currency must match.');
            $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($c->account_id);
            $this->assertAccount($account, $currency);

            return $account;
        }
        abort_unless($allowLegacy || $currency === $business->base_currency || ! empty($data[$prefix.'bank_profile_id']), 422, 'Select a named account for foreign currency.');

        return app(FinanceBankProfiles::class)->account($business, isset($data[$prefix.'bank_profile_id']) ? (int) $data[$prefix.'bank_profile_id'] : null, $data[$prefix.'account_key'] ?? 'cash', $currency);
    }

    private function leg(string $currency, string $rate, ?int $rateId, array $lines, string $amount, ?FinanceAccount $cash = null, ?string $direction = null): array
    {
        return ['currency' => $currency, 'rate_to_base' => $rate, 'exchange_rate_id' => $rateId, 'lines' => $lines, 'amount' => $amount, 'cash_account_id' => $cash?->id, 'cash_direction' => $direction];
    }

    private function guard(int $id, array $basis): array
    {
        return ['type' => 'account', 'id' => $id, 'fingerprint' => $basis['fingerprint']];
    }

    public function prepare(Business $business, string $kind, array $data, int $actor): FinanceWorkflow
    {
        return DB::transaction(function () use ($business, $kind, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $flows = app(FinanceWorkflows::class);
            if ($old = $flows->existing($business, $kind, $data)) {
                return $old;
            }abort_unless(isset(self::KINDS[$kind]), 422, 'Choose an available money task.');
            $math = app(FinanceMath::class);
            $ledger = app(FinanceLedger::class);
            $date = $data['event_on'];
            $currency = $data['currency'];
            $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
            $rate = app(FinanceRates::class)->rate($business, $currency, $rateId, $date);
            $amount = $math->money($data['amount']);
            abort_unless($amount->isGreaterThan(0), 422, 'Enter a positive actual amount.');
            $source = $this->moneyAccount($business, $data);
            $guards = [];
            $details = [];
            if ($kind === 'exchange') {
                $destCurrency = $data['destination_currency'] ?? '';
                abort_unless($destCurrency !== $currency && in_array($destCurrency, app(BusinessCurrencies::class)->enabled($business), true), 422, 'Select a different enabled receiving currency.');
                $dest = $this->moneyAccount($business, $data, 'destination_');
                abort_unless($source->id !== $dest->id, 422, 'Choose different source and destination accounts.');
                $destRateId = isset($data['destination_exchange_rate_id']) ? (int) $data['destination_exchange_rate_id'] : null;
                $destRate = app(FinanceRates::class)->rate($business, $destCurrency, $destRateId, $date);
                $received = $math->money($data['received_amount'] ?? '0');
                abort_unless($received->isGreaterThan(0), 422, 'Enter the actual amount received.');
                $fee = $math->money($data['fee_amount'] ?? '0');
                abort_unless(! $fee->isLessThan(0), 422, 'Fees cannot be negative.');
                $total = $amount->plus($fee);
                $basis = $flows->accountBasis($business->id, $source->id, $date);
                abort_unless(! $basis['units']->isLessThan($total) && $basis['base']->isGreaterThan(0), 422, 'The source account needs sufficient posted money and a valid carrying value.');
                $guards[] = $this->guard($source->id, $basis);
                $carrying = $total->isEqualTo($basis['units']) ? $basis['base'] : $math->money($basis['base']->multipliedBy($total)->dividedBy($basis['units'], 14, RoundingMode::HalfUp));
                $principal = $fee->isZero() ? $carrying : $math->money($carrying->multipliedBy($amount)->dividedBy($total, 14, RoundingMode::HalfUp));
                $feeBase = $math->base($fee, $rate);
                $feeDifference = $principal->plus($feeBase)->minus($carrying);
                $out = [$ledger->line('fx_clearing', (string) $amount, '0', (string) $principal, '0', 'Currency sold at its historical carrying value'), $ledger->line($source->system_key, '0', (string) $total, '0', (string) $carrying, 'Actual currency and source-side charge paid')];
                if ($fee->isGreaterThan(0)) {
                    $out[] = $ledger->line('exchange_fees', (string) $fee, '0', (string) $feeBase, '0', 'Explicit conversion fee in source currency');
                }
                if (! $feeDifference->isZero()) {
                    $out[] = $ledger->line($feeDifference->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $feeDifference->isLessThan(0) ? (string) $feeDifference->abs() : '0', $feeDifference->isGreaterThan(0) ? (string) $feeDifference : '0', 'Fee settlement exchange difference');
                }
                $destBase = $math->base($received, $destRate);
                $difference = $destBase->minus($principal);
                $in = [$ledger->line($dest->system_key, (string) $received, '0', (string) $destBase, '0', 'Actual converted money received'), $ledger->line('fx_clearing', '0', (string) $received, '0', (string) $principal, 'Clear the exact source carrying value')];
                if (! $difference->isZero()) {
                    $in[] = $ledger->line($difference->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', '0', '0', $difference->isLessThan(0) ? (string) $difference->abs() : '0', $difference->isGreaterThan(0) ? (string) $difference : '0', 'Actual conversion against historical carrying value');
                }
                $legs = [$this->leg($currency, $rate, $rateId, $out, (string) $total, $source, 'out'), $this->leg($destCurrency, $destRate, $destRateId, $in, (string) $received, $dest, 'in')];
                $details = ['source_amount' => (string) $amount, 'source_fee' => (string) $fee, 'received' => (string) $received, 'actual_rate' => (string) $received->dividedBy($amount, 10, RoundingMode::HalfUp), 'source_carrying' => (string) $principal, 'destination_base' => (string) $destBase, 'clearing_base' => '0.00'];
            } elseif ($kind === 'cash_topup') {
                $custody = CashCustody::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['custody_id'] ?? 0);
                abort_unless($custody->currency === $currency && $custody->account_id !== $source->id, 422, 'Choose a different cash float in the same currency.');
                $dest = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($custody->account_id);
                $to = $flows->accountBasis($business->id, $dest->id, $date);
                $from = $flows->accountBasis($business->id, $source->id, $date);
                abort_unless(! $to['units']->plus($amount)->isGreaterThan((string) $custody->authorised_float) && ! $from['units']->isLessThan($amount), 422, 'Check the authorised float limit and available funding.');
                $guards = [$this->guard($dest->id, $to), $this->guard($source->id, $from)];
                $base = $math->base($amount, $rate);
                $legs = [$this->leg($currency, $rate, $rateId, [$ledger->line($dest->system_key, (string) $amount, '0', (string) $base, '0', 'Float handed to the named custodian'), $ledger->line($source->system_key, '0', (string) $amount, '0', (string) $base, 'Actual float funding')], (string) $amount, $source, 'out')];
                $details = ['custody_id' => $custody->id, 'staff_person_id' => $custody->staff_person_id];
            } else {
                $base = $math->base($amount, $rate);
                $incoming = $kind === 'owner_contribution';
                if (! $incoming) {
                    $basis = $flows->accountBasis($business->id, $source->id, $date);
                    abort_unless(! $basis['units']->isLessThan($amount), 422, 'Owner drawings exceed posted money in this account.');
                    $guards[] = $this->guard($source->id, $basis);
                }
                $legs = [$this->leg($currency, $rate, $rateId, $incoming ? [$ledger->line($source->system_key, (string) $amount, '0', (string) $base, '0', 'Actual owner capital received'), $ledger->line('owner_capital', '0', (string) $amount, '0', (string) $base, 'Owner equity contribution, separate from sales or borrowing')] : [$ledger->line('owner_drawings', (string) $amount, '0', (string) $base, '0', 'Owner drawings, separate from business expense'), $ledger->line($source->system_key, '0', (string) $amount, '0', (string) $base, 'Actual owner withdrawal')], (string) $amount, $source, $incoming ? 'in' : 'out')];
            }

            return $flows->create($business, $kind, $data, $actor, $legs, $details, $guards);
        }, 3);
    }

    public function createCustody(Business $business, array $data, int $actor): CashCustody
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['staff_person_id']);
            abort_if(CashCustody::query()->where('business_id', $business->id)->where('name', $data['name'])->exists(), 422, 'This cash float name is already in use.');
            if (! empty($data['use_general_cash'])) {
                abort_unless($data['currency'] === $business->base_currency, 422, 'General physical cash uses the reporting currency.');
                $account = app(FinanceSetup::class)->account($business->id, 'cash');
                abort_if(CashCustody::query()->where('business_id', $business->id)->where('account_id', $account->id)->exists(), 422, 'General physical cash already has a custodian.');
            } else {
                $account = FinanceAccount::create(['business_id' => $business->id, 'code' => 'C'.Str::upper(Str::random(8)), 'name' => 'Cash: '.$data['name'], 'type' => 'asset', 'system_key' => 'cash_'.Str::lower(Str::random(22)), 'active' => true]);
            }
            unset($data['use_general_cash']);
            $row = CashCustody::create([...$data, 'business_id' => $business->id, 'account_id' => $account->id, 'active' => true, 'created_by' => $actor]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'cash.custody_created', 'Created an accountable cash float.', [], ['custody_id' => $row->id, 'staff_person_id' => $row->staff_person_id, 'limit' => $row->authorised_float]);

            return $row;
        }, 3);
    }

    public function count(Business $business, CashCustody $custody, array $data, int $actor): CashClose
    {
        return DB::transaction(function () use ($business, $custody, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $custody = CashCustody::query()->where('business_id', $business->id)->where('active', true)->findOrFail($custody->id);
            $date = now($business->timezone)->toDateString();
            app(FinancePeriodControls::class)->assertWritable($business, $date);
            abort_if(CashClose::query()->where('business_id', $business->id)->where('custody_id', $custody->id)->whereIn('status', ['pending', 'variance_pending'])->exists(), 422, 'Finish or reject the previous cash count first.');
            $basis = app(FinanceWorkflows::class)->accountBasis($business->id, $custody->account_id, $date);
            $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
            $rate = app(FinanceRates::class)->rate($business, $custody->currency, $rateId, $date);
            $counted = app(FinanceMath::class)->money($data['counted']);
            abort_unless(! $counted->isLessThan(0), 422, 'Counted cash cannot be negative.');
            if (! empty($data['denominations'])) {
                $sum = BigDecimal::zero();
                foreach ($data['denominations'] as $item) {
                    $sum = $sum->plus(BigDecimal::of($item['value'])->multipliedBy($item['count']));
                }abort_unless($sum->isEqualTo($counted), 422, 'Denominations must add up to the counted cash.');
            }

            $fields = ['business_id' => $business->id, 'custody_id' => $custody->id, 'counted_on' => $date, 'expected' => (string) $basis['units']->toScale(2), 'counted' => (string) $counted, 'exchange_rate_id' => $rateId, 'rate_to_base' => $rate, 'ledger_fingerprint' => $basis['fingerprint'], 'denominations' => $data['denominations'] ?? [], 'reason' => $data['reason'], 'created_by' => $actor];
            $fields['rate_to_base'] = (string) BigDecimal::of($rate)->toScale(10);

            return CashClose::create([...$fields, 'status' => 'pending', 'data_snapshot' => $fields, 'snapshot_hash' => app(FinanceWorkflows::class)->hash($fields)]);
        }, 3);
    }

    public function reviewCount(Business $business, CashClose $count, bool $approve, string $note, int $actor): void
    {
        DB::transaction(function () use ($business, $count, $approve, $note, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $count = CashClose::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($count->id);
            abort_unless($count->status === 'pending' && $count->created_by !== $actor, 422, 'A different authorised person must review this pending cash count.');
            if (! $approve) {
                $count->update(['status' => 'rejected', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);

                return;
            }
            $this->verifyCount($count);
            $custody = CashCustody::query()->where('business_id', $business->id)->findOrFail($count->custody_id);
            $flows = app(FinanceWorkflows::class);
            $basis = $flows->accountBasis($business->id, $custody->account_id, $count->counted_on->toDateString());
            abort_unless($basis['fingerprint'] === $count->ledger_fingerprint, 422, 'Money moved after the count. Reject it and recount.');
            $delta = BigDecimal::of((string) $count->counted)->minus((string) $count->expected);
            $workflow = null;
            if (! $delta->isZero()) {
                $amount = (string) $delta->abs()->toScale(2);
                $varianceBase = app(FinanceMath::class)->base($amount, (string) $count->rate_to_base);
                if ($delta->isLessThan(0) && $basis['units']->isGreaterThan(0)) {
                    $varianceBase = $delta->abs()->isEqualTo($basis['units']) ? $basis['base'] : app(FinanceMath::class)->money($basis['base']->multipliedBy($delta->abs())->dividedBy($basis['units'], 14, RoundingMode::HalfUp));
                }$base = (string) $varianceBase;
                $account = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($custody->account_id);
                $l = app(FinanceLedger::class);
                $lines = $delta->isLessThan(0) ? [$l->line('cash_shortage', $amount, '0', $base, '0', 'Approved cash shortage'), $l->line($account->system_key, '0', $amount, '0', $base, 'Cash count variance')] : [$l->line($account->system_key, $amount, '0', $base, '0', 'Cash count variance'), $l->line('cash_surplus', '0', $amount, '0', $base, 'Approved cash surplus')];
                $workflow = $flows->create($business, 'cash_variance', ['event_on' => $count->counted_on->toDateString(), 'reason' => $count->reason.' · '.$note, 'external_reference' => 'CASH-COUNT-'.$count->id, 'request_key' => (string) Str::uuid()], $count->created_by, [$this->leg($custody->currency, (string) $count->rate_to_base, $count->exchange_rate_id, $lines, $amount)], ['cash_close_id' => $count->id, 'count_snapshot_hash' => $count->snapshot_hash], [$this->guard($custody->account_id, $basis)]);
            }
            $this->assertCashComplete($business, $custody, $count->counted_on->toDateString());
            $count->update(['status' => $workflow ? 'variance_pending' : 'closed', 'workflow_id' => $workflow?->id, 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_note' => $note]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'cash.count_reviewed', $note, [], ['cash_close_id' => $count->id, 'workflow_id' => $workflow?->id]);
        }, 3);
    }

    public function verifyCount(CashClose $count): void
    {
        $proof = $count->data_snapshot;
        abort_unless(is_array($proof) && $count->snapshot_hash && hash_equals($count->snapshot_hash, app(FinanceWorkflows::class)->hash($proof)), 422, 'The original cash-count evidence is missing or changed.');
        foreach ($proof as $key => $value) {
            $actual = $key === 'counted_on' ? $count->counted_on->toDateString() : $count->{$key};
            $same = in_array($key, ['expected', 'counted', 'rate_to_base'], true) ? BigDecimal::of((string) $actual)->isEqualTo((string) $value) : $actual === $value;
            abort_unless($same, 422, 'A frozen cash count field changed. Reject and record the correct observation.');
        }
    }

    public function countIsCurrent(CashClose $count): bool
    {
        try {
            $this->verifyCount($count);
            if ($count->status !== 'closed' || ! $count->reviewed_by || $count->created_by === $count->reviewed_by) {
                return false;
            }
            $custody = CashCustody::query()->where('business_id', $count->business_id)->findOrFail($count->custody_id);
            $basis = app(FinanceWorkflows::class)->accountBasis($count->business_id, $custody->account_id, $count->counted_on->toDateString(), true, $count->workflow_id);
            if ($basis['fingerprint'] !== $count->ledger_fingerprint) {
                return false;
            }
            $delta = BigDecimal::of((string) $count->counted)->minus((string) $count->expected);
            if ($delta->isZero()) {
                return $count->workflow_id === null;
            }
            $flow = FinanceWorkflow::query()->where('business_id', $count->business_id)->findOrFail($count->workflow_id);

            return $flow->kind === 'cash_variance' && $flow->posting_status === 'posted' && ($flow->data_snapshot['details']['count_snapshot_hash'] ?? null) === $count->snapshot_hash;
        } catch (HttpException|ValidationException) {
            return false;
        }
    }

    public function assertCashComplete(Business $business, CashCustody $custody, string $date): void
    {
        $key = FinanceAccount::query()->where('business_id', $business->id)->findOrFail($custody->account_id)->system_key;
        foreach (FinanceWorkflowLeg::query()->where('business_id', $business->id)->whereDate('event_on', '<=', $date)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted')->where('kind', '!=', 'cash_variance'))->cursor() as $leg) {
            abort_if(collect($leg->lines)->contains(fn ($line) => $line['account'] === $key), 422, 'Finish pending cash funding, transfers and opening events before closing this cash day.');
        }
        foreach ([[FinancePayment::class, 'payment_on'], [FinanceShipmentCost::class, 'paid_on'], [FinancePayrollSettlement::class, 'paid_on']] as [$model, $field]) {
            abort_if($model::query()->where('business_id', $business->id)->where('cash_account_id', $custody->account_id)->where('status', 'pending')->whereDate($field, '<=', $date)->exists(), 422, 'Finish pending receipts, shipment payments and wage payments before closing this cash day.');
        }
        foreach ([FinanceOperation::class, FinanceRegisterEntry::class] as $model) {
            abort_if($model::query()->where('business_id', $business->id)->where(fn ($q) => $q->where('cash_account_id', $custody->account_id)->when($model === FinanceOperation::class, fn ($q) => $q->orWhere('destination_account_id', $custody->account_id)))->where('posting_status', 'not_posted')->whereDate('event_on', '<=', $date)->exists(), 422, 'Finish or withdraw unposted cash vouchers before closing this float.');
        }
        abort_if(FinanceWorkflowLeg::query()->where('business_id', $business->id)->where('cash_account_id', $custody->account_id)->whereDate('event_on', '<=', $date)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'not_posted'))->exists(), 422, 'Finish pending float funding or money events before closing.');
    }

    public function recurring(Business $business, array $data, int $actor): RecurringObligation
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            foreach (['supplier_id' => Supplier::class, 'staff_person_id' => StaffPerson::class] as $field => $model) {
                if (! empty($data[$field])) {
                    $model::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data[$field]);
                }
            }abort_unless(empty($data['supplier_id']) || empty($data['staff_person_id']), 422, 'Choose one payee.');

            return RecurringObligation::create([...$data, 'business_id' => $business->id, 'active' => true, 'created_by' => $actor]);
        }, 3);
    }

    public function recognise(Business $business, RecurringObligation $obligation, array $data, int $actor): FinanceOperation
    {
        return DB::transaction(function () use ($business, $obligation, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $obligation = RecurringObligation::query()->where('business_id', $business->id)->where('active', true)->lockForUpdate()->findOrFail($obligation->id);
            $previous = DB::table('recurring_occurrences')->where('business_id', $business->id)->where('obligation_id', $obligation->id)->where('due_on', $data['due_on'])->first();
            if ($previous) {
                $old = FinanceOperation::query()->where('business_id', $business->id)->findOrFail($previous->operation_id);
                abort_unless($old->idempotency_key === $data['request_key'] && $old->external_reference === $data['external_reference'] && $old->event_on->toDateString() === $data['event_on'] && $old->exchange_rate_id === ($data['exchange_rate_id'] ?? null), 422, 'This recurring occurrence was already recognised with different details.');

                return $old;
            }$due = $obligation->next_due_on->toDateString();
            abort_unless($data['due_on'] === $due && $due <= now($business->timezone)->toDateString(), 422, 'Recognise only the currently due occurrence.');
            $row = app(FinanceOperations::class)->prepare($business, ['kind' => 'expense', 'amount' => $obligation->amount, 'currency' => $obligation->currency, 'event_on' => $data['event_on'], 'due_on' => $due, 'description' => $obligation->name, 'external_reference' => $data['external_reference'], 'category' => $obligation->category, 'supplier_id' => $obligation->supplier_id, 'staff_person_id' => $obligation->staff_person_id, 'exchange_rate_id' => $data['exchange_rate_id'] ?? null, 'idempotency_key' => $data['request_key']], $actor);
            DB::table('recurring_occurrences')->insert(['business_id' => $business->id, 'obligation_id' => $obligation->id, 'due_on' => $due, 'operation_id' => $row->id, 'created_at' => now(), 'updated_at' => now()]);
            $obligation->update(['next_due_on' => $obligation->next_due_on->copy()->addMonthsNoOverflow($obligation->interval_months)]);

            return $row;
        }, 3);
    }
}
