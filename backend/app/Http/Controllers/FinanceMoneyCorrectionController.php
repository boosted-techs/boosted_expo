<?php

namespace App\Http\Controllers;

use App\Models\FinanceBankStatementLine;
use App\Models\FinanceMoneyCorrection;
use App\Models\FinanceOperation;
use App\Services\FinanceMoneyCorrections;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceMoneyCorrectionController extends Controller
{
    private function source(Request $r, int $operation): FinanceOperation
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create'), 403);

        return FinanceOperation::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($operation);
    }

    public function create(Request $r, int $operation, FinanceMoneyCorrections $corrections): View
    {
        $source = $this->source($r, $operation);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $remaining = $corrections->remaining($source);
        $history = FinanceMoneyCorrection::query()->where('business_id', $business->id)->where('operation_id', $source->id)->with('workflow')->latest('id')->workspacePaginate(25, ['*'], 'history_page')->withQueryString();
        $bankLines = FinanceBankStatementLine::query()->where('business_id', $business->id)->whereHas('statement', fn ($q) => $q->where('status', 'open')->where('currency', $source->currency)->whereIn('account_id', array_filter([$source->cash_account_id, $source->destination_account_id])))->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('bank_reference', 'like', '%'.$r->input('search').'%')->orWhere('description', 'like', '%'.$r->input('search').'%')))->orderByDesc('transaction_on')->orderByDesc('id')->workspacePaginate(30, ['*'], 'bank_page')->withQueryString();

        return view('finance.money.correct', compact('business', 'source', 'remaining', 'history', 'bankLines'));
    }

    public function store(Request $r, int $operation, FinanceMoneyCorrections $corrections): RedirectResponse
    {
        $source = $this->source($r, $operation);
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'return_bank_line_id' => ['nullable', 'integer', 'min:1'], 'other_bank_line_id' => ['nullable', 'integer', 'min:1'], 'cash_witness' => ['nullable', 'string', 'min:10', 'max:1000'], 'external_reference' => ['required', 'string', 'min:3', 'max:150'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $flow = $corrections->prepare($business, $source, $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Correction prepared against the original journal. Independent approval is required before any balance is restored.');
    }
}
