<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashCustody;
use App\Models\FinanceAccount;
use App\Models\FinanceBankProfile;
use App\Models\FinanceJournalLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceBankProfiles
{
    public function create(Business $business, array $data, int $actor): FinanceBankProfile
    {
        return DB::transaction(function () use ($business, $data, $actor) {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(FinanceSetup::class)->account($business->id, 'bank');
            if (FinanceBankProfile::query()->where('business_id', $business->id)
                ->where('label', $data['label'])->exists()) {
                throw ValidationException::withMessages(['label' => 'A bank account with this name already exists.']);
            }
            if (! empty($data['use_legacy_bank'])) {
                $account = app(FinanceSetup::class)->account($business->id, 'bank');
                abort_if(FinanceBankProfile::query()->where('business_id', $business->id)->where('account_id', $account->id)->exists(), 422, 'The historical bank ledger already has an account identity.');
                $currencies = FinanceJournalLine::query()->where('finance_journal_lines.business_id', $business->id)->where('account_id', $account->id)->join('finance_journals as j', 'j.id', '=', 'finance_journal_lines.journal_id')->where('j.business_id', $business->id)->distinct()->pluck('j.currency')->all();
                abort_unless(array_diff($currencies, [strtoupper($data['currency'])]) === [], 422, 'This historical ledger mixes currencies. Reconstruct and reconcile each actual account before assigning a single-currency identity.');
            } else {
                $account = FinanceAccount::create([
                    'business_id' => $business->id,
                    'code' => 'B'.Str::upper(Str::random(8)),
                    'name' => 'Bank: '.$data['label'], 'type' => 'asset',
                    'system_key' => 'bank_'.Str::lower(Str::random(22)), 'active' => true,
                ]);
            }

            return FinanceBankProfile::create([
                'business_id' => $business->id, 'account_id' => $account->id,
                'label' => $data['label'], 'institution' => $data['institution'],
                'currency' => strtoupper($data['currency']),
                'masked_reference' => $data['masked_reference'] ?? null,
                'created_by' => $actor, 'active' => true,
            ]);
        }, 3);
    }

    public function account(Business $business, ?int $profileId,
        string $legacyKey, string $currency): FinanceAccount
    {
        if ($profileId !== null) {
            $profile = FinanceBankProfile::query()->where('business_id', $business->id)
                ->where('active', true)->with('account')->findOrFail($profileId);
            if (strtoupper($profile->currency) !== strtoupper($currency)) {
                throw ValidationException::withMessages(['bank_profile_id' => 'Bank account currency must exactly match the recorded payment currency. Cross-currency transfers need a separate reviewed conversion.']);
            }
            abort_unless($profile->account?->active && $profile->account->business_id === $business->id, 422,
                'The selected bank account is not active.');

            return $profile->account;
        }
        abort_unless(in_array($legacyKey, ['bank', 'cash'], true), 422, 'Select cash or a bank account.');
        if ($legacyKey === 'bank' && FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('active', true)->exists()) {
            throw ValidationException::withMessages(['bank_profile_id' => 'Choose a named bank account for this new transaction. The original generic bank ledger is retained for historical records.']);
        }

        return app(FinanceSetup::class)->account($business->id, $legacyKey);
    }

    public function assertPaymentAccount(FinanceAccount $account, string $currency): void
    {
        if (str_starts_with($account->system_key, 'cash_')) {
            app(FinanceTreasury::class)->assertAccount($account, $currency);

            return;
        }
        $custody = CashCustody::query()->where('business_id', $account->business_id)->where('account_id', $account->id)->first();
        if ($custody) {
            abort_unless($account->active && $custody->active && $custody->currency === strtoupper($currency), 422, 'The physical cash account is inactive or has another currency.');

            return;
        }
        $existingProfile = FinanceBankProfile::query()->where('business_id', $account->business_id)->where('account_id', $account->id)->first();
        if ($existingProfile) {
            abort_unless($existingProfile->active && $account->active && $existingProfile->currency === strtoupper($currency), 422, 'The bank account is inactive or has another currency.');

            return;
        }
        if (in_array($account->system_key, ['cash', 'bank'], true)) {
            return;
        }
        $profile = FinanceBankProfile::query()->where('business_id', $account->business_id)
            ->where('account_id', $account->id)->where('active', true)->first();
        abort_unless($profile && $account->active && $profile->currency === strtoupper($currency),
            422, 'The selected bank ledger is inactive or has a different currency.');
    }

    public function statementAccount(Business $business, int|string|null $profileId, string $currency): FinanceAccount
    {
        // Existing bank history remains reconcilable after named bank ledgers
        // are created, but only when staff explicitly chooses the legacy ledger.
        if ($profileId === 'legacy') {
            $account = app(FinanceSetup::class)->account($business->id, 'bank');
            $this->assertPaymentAccount($account, $currency);

            return $account;
        }

        return $this->account($business, $profileId === null ? null : (int) $profileId, 'bank', $currency);
    }
}
