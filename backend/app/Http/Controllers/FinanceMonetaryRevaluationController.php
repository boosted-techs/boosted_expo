<?php

namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceMonetaryRevaluation;
use App\Models\FinanceSupplierBill;
use App\Services\FinanceDocuments;
use App\Services\FinanceMonetaryRevaluations;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceMonetaryRevaluationController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'finance.monetary.revaluation.view');
        $business = $request->attributes->get('activeBusiness');
        $invoices = CommercialInvoice::query()->where('business_id', $business->id)
            ->where('posting_status', 'posted')->where('currency', '!=', $business->base_currency)
            ->orderByDesc('id')->limit(100)->get()->filter(fn ($doc) => app(FinanceDocuments::class)->outstanding($doc, false)->isGreaterThan(0));
        $bills = FinanceSupplierBill::query()->where('business_id', $business->id)
            ->where('posting_status', 'posted')->where('currency', '!=', $business->base_currency)
            ->orderByDesc('id')->limit(100)->get()->filter(fn ($doc) => app(FinanceDocuments::class)->outstanding($doc, false)->isGreaterThan(0));
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)
            ->where('status', 'approved')->orderByDesc('effective_on')->limit(125)->get();
        $records = FinanceMonetaryRevaluation::query()->where('business_id', $business->id)
            ->orderByDesc('id')->workspacePaginate(30);
        $canPrepare = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.monetary.revaluation.prepare');

        return view('finance.monetary-revaluations', compact(
            'business', 'invoices', 'bills', 'rates', 'records', 'canPrepare'));
    }

    public function prepare(Request $request, FinanceMonetaryRevaluations $service): RedirectResponse
    {
        $this->check($request, 'finance.monetary.revaluation.prepare');
        $data = $request->validate([
            'source_type' => ['required', 'in:commercial_invoice,supplier_bill'],
            'source_id' => ['required', 'integer', 'min:1'],
            'exchange_rate_id' => ['required', 'integer', 'min:1'],
            'valued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valuation_evidence' => ['required', 'string', 'min:30', 'max:3000'],
        ]);
        $service->prepare($request->attributes->get('activeBusiness'),
            $data, $request->user()->id);

        return redirect()->route('finance.monetary-revaluations.index')->with('status',
            'Foreign monetary item revaluation prepared. No invoice amount, supplier obligation or bank transaction has been changed until independent journal approval.');
    }
}
