<?php

namespace App\Services;

use App\Models\AssetActivity;
use App\Models\Business;
use App\Models\BusinessAsset;
use App\Models\FinanceOperation;
use App\Models\FinanceRegisterEntry;
use App\Models\StaffPerson;
use App\Support\BusinessCurrencies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class AssetRegister
{
    public const KINDS = ['asset_purchase' => 'Record equipment acquisition', 'asset_opening' => 'Record existing equipment opening value', 'asset_payment' => 'Pay an unpaid equipment bill', 'asset_depreciation' => 'Post one month of depreciation', 'asset_disposal' => 'Dispose / write off equipment', 'register_partial_return' => 'Record a partial actual cash return', 'register_reversal' => 'Correct the latest posted entry'];

    public function create(Business $business, array $data, int $actor): BusinessAsset
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            if (! empty($data['staff_person_id'])) {
                StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['staff_person_id']);
            }
            if (! empty($data['depreciation_start_on'])) {
                abort_unless(substr($data['depreciation_start_on'], 8, 2) === '01', 422, 'The depreciation policy must start on the first of a month.');
            }
            abort_unless(! BusinessAsset::query()->where('business_id', $business->id)->where('asset_code', $data['asset_code'])->exists(), 422, 'This asset code is already in use.');
            $asset = BusinessAsset::create([...$data, 'business_id' => $business->id, 'created_by' => $actor, 'operating_status' => 'available', 'condition' => $data['condition'] ?? 'good']);
            app(PlatformAccess::class)->audit($business->id, $actor, 'asset.registered', 'Registered equipment with its ownership and custody.', [], ['asset_id' => $asset->id, 'asset_code' => $asset->asset_code, 'ownership' => $asset->ownership]);

            return $asset;
        }, 3);
    }

    public function correctProfile(Business $business, BusinessAsset $asset, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $asset, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $asset = BusinessAsset::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($asset->id);
            $reason = $data['reason'];
            unset($data['reason']);
            abort_if(BusinessAsset::query()->where('business_id', $business->id)->where('asset_code', $data['asset_code'])->whereKeyNot($asset->id)->exists(), 422, 'Another asset already uses this code.');
            $policy = array_intersect_key($data, array_flip(['useful_life_months', 'residual_base', 'depreciation_start_on']));
            if ($policy) {
                abort_unless(! $this->state($asset)['origin'] && ! FinanceRegisterEntry::query()->where('business_id', $business->id)->where('asset_id', $asset->id)->where('posting_status', 'not_posted')->exists(), 422, 'The financial policy is frozen after preparation or recognition. Correct the pending financial source first.');
                $life = $policy['useful_life_months'] ?? $asset->useful_life_months;
                $start = array_key_exists('depreciation_start_on', $policy) ? $policy['depreciation_start_on'] : $asset->depreciation_start_on?->toDateString();
                abort_unless((bool) $life === (bool) $start, 422, 'Set both the remaining useful life and its depreciation start month.');
                if (! empty($policy['depreciation_start_on'])) {
                    abort_unless(substr($policy['depreciation_start_on'], 8, 2) === '01', 422, 'The depreciation policy must start on the first of a month.');
                }
            }
            $before = $asset->only(array_keys($data));
            $asset->update($data);
            app(PlatformAccess::class)->audit($business->id, $actor, 'asset.profile_corrected', $reason, $before, $asset->only(array_keys($data)));
        }, 3);
    }

    public function state(BusinessAsset $asset, ?array $position = null): array
    {
        $p = $position ?? app(FinanceRegisterPostings::class)->position($asset);
        $cost = $p['balances']['asset_cost']['base'] ?? BigDecimal::zero();
        $accum = ($p['balances']['asset_accumulated']['base'] ?? BigDecimal::zero())->negated();
        $payable = ($p['balances']['asset_payable']['units'] ?? BigDecimal::zero())->negated();
        $payableBase = ($p['balances']['asset_payable']['base'] ?? BigDecimal::zero())->negated();
        $active = $p['entries']->where('posting_status', 'posted');
        $origin = $active->first(fn ($e) => in_array($e->kind, ['asset_purchase', 'asset_opening'], true));

        return [...$p, 'cost' => $cost, 'accumulated' => $accum, 'book' => $cost->minus($accum), 'payable' => $payable, 'payable_base' => $payableBase, 'origin' => $origin, 'disposed' => $active->contains('kind', 'asset_disposal'), 'months' => $active->where('kind', 'asset_depreciation')->count()];
    }

    public function activity(Business $business, BusinessAsset $asset, array $data, int $actor): void
    {
        DB::transaction(function () use ($business, $asset, $data, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'finance');
            $asset = BusinessAsset::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($asset->id);
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = AssetActivity::query()->where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless($prior->asset_id === $asset->id && $prior->payload_hash === $hash, 422, 'This activity request was already used for other details.');

                return;
            }
            abort_unless($data['event_on'] <= now($business->timezone)->toDateString(), 422, 'Record an actual activity date. Use maintenance due date for planned work.');
            $latest = AssetActivity::query()->where('business_id', $business->id)->where('asset_id', $asset->id)->max('event_on');
            abort_unless(! $latest || $data['event_on'] >= substr((string) $latest, 0, 10), 422, 'New custody or condition changes cannot precede the latest activity.');
            abort_unless(! $this->state($asset)['disposed'], 422, 'Posted disposal has closed this asset. Correct it before further custody changes.');
            $before = $asset->only(['staff_person_id', 'location', 'condition', 'operating_status', 'maintenance_due_on']);
            $changes = [];
            if ($data['kind'] === 'assign') {
                $person = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->findOrFail($data['staff_person_id'] ?? 0);
                abort_unless($asset->operating_status !== 'maintenance', 422, 'Complete the maintenance activity before assigning equipment.');
                $changes = ['staff_person_id' => $person->id, 'location' => $data['location'], 'operating_status' => 'assigned'];
            } elseif ($data['kind'] === 'return') {
                abort_unless($asset->staff_person_id !== null, 422, 'This asset has no current custodian to return it.');
                $changes = ['staff_person_id' => null, 'location' => $data['location'], 'operating_status' => 'available', 'condition' => $data['condition']];
            } elseif ($data['kind'] === 'maintenance_start') {
                abort_unless($asset->operating_status !== 'maintenance', 422, 'Maintenance is already open.');
                $changes = ['operating_status' => 'maintenance', 'condition' => 'under_repair'];
            } elseif ($data['kind'] === 'maintenance_complete') {
                abort_unless($asset->operating_status === 'maintenance', 422, 'No open maintenance activity exists.');
                $changes = ['operating_status' => $asset->staff_person_id ? 'assigned' : 'available', 'condition' => $data['condition'], 'maintenance_due_on' => $data['maintenance_due_on'] ?? null];
            } elseif ($data['kind'] === 'condition') {
                $changes = ['condition' => $data['condition']];
            } elseif ($data['kind'] === 'maintenance_plan') {
                abort_unless(! empty($data['maintenance_due_on']), 422, 'Choose the next maintenance due date.');
                $changes = ['maintenance_due_on' => $data['maintenance_due_on']];
            } else {
                abort(422, 'Unsupported asset activity.');
            }
            if (! empty($data['expense_id'])) {
                $expense = FinanceOperation::query()->where('business_id', $business->id)->whereIn('kind', ['expense', 'paid_expense', 'staff_expense'])->findOrFail($data['expense_id']);
                abort_if(AssetActivity::query()->where('business_id', $business->id)->where('expense_id', $expense->id)->exists(), 422, 'This maintenance expense is already linked.');
            }
            $asset->update($changes);
            AssetActivity::create(['business_id' => $business->id, 'asset_id' => $asset->id, 'kind' => $data['kind'], 'event_on' => $data['event_on'], 'reference' => $data['reference'], 'note' => $data['note'], 'before' => $before, 'after' => $asset->only(array_keys($before)), 'expense_id' => $data['expense_id'] ?? null, 'created_by' => $actor, 'request_key' => $data['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }

    public function financial(Business $business, BusinessAsset $asset, string $kind, array $data, array $position): array
    {
        abort_unless(isset(self::KINDS[$kind]) && $asset->ownership === 'owned', 422, 'Only owned equipment supports financial asset postings.');
        $state = $this->state($asset, $position);
        $math = app(FinanceMath::class);
        $ledger = app(FinanceLedger::class);
        $currency = in_array($kind, ['asset_opening', 'asset_depreciation'], true) ? $business->base_currency : ($kind === 'asset_payment' ? $state['origin']?->currency : strtoupper($data['currency'] ?? ($kind === 'asset_disposal' ? $business->base_currency : '')));
        abort_unless($currency && in_array($currency, app(BusinessCurrencies::class)->enabled($business), true), 422, 'Choose an enabled business currency.');
        $rateId = isset($data['exchange_rate_id']) ? (int) $data['exchange_rate_id'] : null;
        $rate = app(FinanceRates::class)->rate($business, $currency, $rateId, $data['event_on']);
        $prepared = ['currency' => $currency, 'exchange_rate_id' => $rateId, 'rate_to_base' => $rate, 'amount' => '0.00', 'lines' => []];
        $lines = [];
        if (in_array($kind, ['asset_purchase', 'asset_opening'], true)) {
            abort_unless(! $state['origin'] && ! $state['disposed'], 422, 'This asset already has an opening or acquisition.');
            $amount = $math->money($data['amount'] ?? '0');
            abort_unless($amount->isGreaterThan(0), 422, 'Enter a positive equipment cost.');
            $base = $math->base($amount, $rate);
            abort_unless($base->isGreaterThanOrEqualTo((string) $asset->residual_base), 422, 'Residual value cannot exceed the recorded cost.');
            $prepared['amount'] = (string) $amount;
            $lines[] = $ledger->line('asset_cost', (string) $amount, '0', (string) $base, '0', 'Owned equipment acquisition cost');
            if ($kind === 'asset_opening') {
                $accum = $math->money($data['opening_accumulated'] ?? '0');
                abort_unless(! $accum->isLessThan(0) && ! $accum->isGreaterThan($base) && $base->minus($accum)->isGreaterThanOrEqualTo((string) $asset->residual_base), 422, 'Opening accumulated depreciation and residual value exceed the opening cost.');
                if ($accum->isGreaterThan(0)) {
                    $lines[] = $ledger->line('asset_accumulated', '0', (string) $accum, '0', (string) $accum, 'Verified opening accumulated depreciation');
                }
                $net = $base->minus($accum);
                if ($net->isGreaterThan(0)) {
                    $lines[] = $ledger->line('opening_equity', '0', (string) $net, '0', (string) $net, 'Existing equipment opening net book value');
                }
            } elseif ($data['paid'] ?? false) {
                $cash = app(FinanceRegisterPostings::class)->cash($business, $currency, $data);
                $prepared = [...$prepared, 'cash_account_id' => $cash->id, 'cash_direction' => 'out', 'cash_amount' => (string) $amount];
                $lines[] = $ledger->line($cash->system_key, '0', (string) $amount, '0', (string) $base, 'Actual equipment purchase payment');
            } else {
                $lines[] = $ledger->line('asset_payable', '0', (string) $amount, '0', (string) $base, 'Equipment acquisition payable');
            }
            $prepared['details'] = ['due_on' => $data['due_on'] ?? null, 'policy' => ['method' => 'straight_line_monthly', 'remaining_months' => $asset->useful_life_months, 'residual_base' => (string) $asset->residual_base, 'starts_on' => $asset->depreciation_start_on?->toDateString()]];
        } else {
            abort_unless($state['origin'] && ! $state['disposed'], 422, 'Post the asset opening/acquisition before later financial events.');
            if ($kind === 'asset_payment') {
                $amount = $math->money($data['amount'] ?? '0');
                abort_unless($amount->isGreaterThan(0) && ! $amount->isGreaterThan($state['payable']), 422, 'Payment exceeds the posted equipment payable.');
                $cash = app(FinanceRegisterPostings::class)->cash($business, $currency, $data);
                $base = $math->base($amount, $rate);
                $carrying = $amount->isEqualTo($state['payable']) ? $state['payable_base'] : $math->money($state['payable_base']->multipliedBy($amount)->dividedBy($state['payable'], 14, RoundingMode::HalfUp));
                $lines = [$ledger->line('asset_payable', (string) $amount, '0', (string) $carrying, '0', 'Equipment bill settled'), $ledger->line($cash->system_key, '0', (string) $amount, '0', (string) $base, 'Actual equipment payment')];
                $this->difference($lines, $carrying->minus($base));
                $prepared = [...$prepared, 'amount' => (string) $amount, 'cash_account_id' => $cash->id, 'cash_direction' => 'out', 'cash_amount' => (string) $amount];
            } elseif ($kind === 'asset_depreciation') {
                abort_unless($asset->depreciation_start_on && $asset->useful_life_months && $state['months'] < $asset->useful_life_months, 422, 'Configure the remaining useful life and monthly depreciation start at registration.');
                $period = $asset->depreciation_start_on->copy()->addMonthsNoOverflow($state['months']);
                abort_unless($data['event_on'] >= $period->copy()->endOfMonth()->toDateString() && ($data['depreciation_month'] ?? $period->format('Y-m')) === $period->format('Y-m'), 422, 'Post the next completed depreciation month: '.$period->format('F Y').'.');
                $depreciable = $state['book']->minus((string) $asset->residual_base);
                abort_unless($depreciable->isGreaterThan(0), 422, 'No depreciable carrying value remains.');
                $amount = $math->money($depreciable->dividedBy($asset->useful_life_months - $state['months'], 14, RoundingMode::HalfUp));
                abort_unless($amount->isGreaterThan(0), 422, 'The monthly amount is below ledger precision.');
                $prepared['amount'] = (string) $amount;
                $prepared['details'] = ['month' => $period->format('Y-m'), 'method' => 'straight_line_monthly', 'remaining_months' => $asset->useful_life_months - $state['months']];
                $lines = [$ledger->line('asset_depreciation', (string) $amount, '0', (string) $amount, '0', 'Approved monthly straight-line policy'), $ledger->line('asset_accumulated', '0', (string) $amount, '0', (string) $amount, 'Accumulated depreciation')];
            } elseif ($kind === 'asset_disposal') {
                abort_unless($state['payable']->isZero(), 422, 'Settle or separately resolve the equipment bill before disposal.');
                $proceeds = $math->money($data['amount'] ?? '0');
                abort_unless(! $proceeds->isLessThan(0), 422, 'Proceeds cannot be negative.');
                abort_unless($proceeds->isGreaterThan(0) || $currency === $business->base_currency, 422, 'A write-off without proceeds uses the reporting currency.');
                $proceedsBase = $math->base($proceeds, $rate);
                $units = fn (BigDecimal $value) => $currency === $business->base_currency ? $value : $math->money($value->dividedBy($rate, 14, RoundingMode::HalfUp));
                if ($proceeds->isGreaterThan(0)) {
                    $cash = app(FinanceRegisterPostings::class)->cash($business, $currency, $data);
                    $lines[] = $ledger->line($cash->system_key, (string) $proceeds, '0', (string) $proceedsBase, '0', 'Actual disposal proceeds');
                    $prepared = [...$prepared, 'cash_account_id' => $cash->id, 'cash_direction' => 'in', 'cash_amount' => (string) $proceeds];
                }
                if ($state['accumulated']->isGreaterThan(0)) {
                    $lines[] = $ledger->line('asset_accumulated', (string) $units($state['accumulated']), '0', (string) $state['accumulated'], '0', 'Remove historical accumulated depreciation');
                }
                $lines[] = $ledger->line('asset_cost', '0', (string) $units($state['cost']), '0', (string) $state['cost'], 'Remove historical equipment cost');
                $gain = $proceedsBase->minus($state['book']);
                if (! $gain->isZero()) {
                    $v = (string) $units($gain->abs());
                    $lines[] = $ledger->line($gain->isGreaterThan(0) ? 'asset_disposal_gain' : 'asset_disposal_loss', $gain->isLessThan(0) ? $v : '0', $gain->isGreaterThan(0) ? $v : '0', $gain->isLessThan(0) ? (string) $gain->abs() : '0', $gain->isGreaterThan(0) ? (string) $gain : '0', 'Disposal result at the approved proceeds rate');
                }
                $unitNet = BigDecimal::zero();
                foreach ($lines as $line) {
                    $unitNet = $unitNet->plus($line['debit'])->minus($line['credit']);
                }if (! $unitNet->isZero()) {
                    $lines[] = $ledger->line($unitNet->isGreaterThan(0) ? 'fx_gain' : 'fx_loss', $unitNet->isLessThan(0) ? (string) $unitNet->abs() : '0', $unitNet->isGreaterThan(0) ? (string) $unitNet : '0', '0', '0', 'Document-currency rounding of nonmonetary asset values');
                }
                $prepared['details'] = ['proceeds_currency' => $currency, 'proceeds_base' => (string) $proceedsBase, 'historical_cost_base' => (string) $state['cost'], 'historical_accumulated_base' => (string) $state['accumulated'], 'book_base' => (string) $state['book']];
                $prepared['amount'] = (string) $proceeds;
            }
        }
        $prepared['lines'] = $lines;

        return $prepared;
    }

    private function difference(array &$lines, BigDecimal $difference): void
    {
        if ($difference->isGreaterThan(0)) {
            $lines[] = app(FinanceLedger::class)->line('fx_gain', '0', '0', '0', (string) $difference, 'Realised equipment payable FX gain');
        } elseif ($difference->isLessThan(0)) {
            $lines[] = app(FinanceLedger::class)->line('fx_loss', '0', '0', (string) $difference->negated(), '0', 'Realised equipment payable FX loss');
        }
    }
}
