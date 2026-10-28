<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceFxRevaluation;
use App\Services\FinanceFxRevaluations;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceFxRevaluationController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'finance.fx.revaluation.view');
        $business = $request->attributes->get('activeBusiness');
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)
            ->where('active', true)->where('currency', '!=', $business->base_currency)
            ->with('account')->orderBy('label')->get();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->where('status', 'approved')->orderByDesc('effective_on')->limit(100)->get();
        $records = FinanceFxRevaluation::query()->where('business_id', $business->id)
            ->with('bankProfile', 'exchangeRate')->orderByDesc('id')->workspacePaginate(30);
        $chartReady = FinanceAccount::query()->where('business_id', $business->id)
            ->where('active', true)->whereIn('system_key', [
                'unrealized_fx_gain', 'unrealized_fx_loss',
            ])->count() === 2;
        $canPrepare = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.fx.revaluation.prepare');

        return view('finance.fx-revaluations', compact(
            'business', 'banks', 'rates', 'records', 'canPrepare', 'chartReady'));
    }

    public function prepare(Request $request, FinanceFxRevaluations $service): RedirectResponse
    {
        $this->check($request, 'finance.fx.revaluation.prepare');
        $data = $request->validate([
            'bank_profile_id' => ['required', 'integer', 'min:1'],
            'exchange_rate_id' => ['required', 'integer', 'min:1'],
            'valued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valuation_evidence' => ['required', 'string', 'min:30', 'max:3000'],
        ]);
        $service->prepare($request->attributes->get('activeBusiness'),
            $data, $request->user()->id);

        return redirect()->route('finance.fx-revaluations.index')->with('status',
            'Non-cash foreign bank revaluation prepared for independent review. No foreign-currency units or bank funds have been moved.');
    }
}
