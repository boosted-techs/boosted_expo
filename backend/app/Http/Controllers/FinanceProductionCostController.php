<?php

namespace App\Http\Controllers;

use App\Models\FinanceOperation;
use App\Models\FinancePayrollRun;
use App\Models\FinanceWorkflow;
use App\Models\MaterialLot;
use App\Models\StockBatch;
use App\Services\FinanceProductionCosts;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceProductionCostController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r, FinanceProductionCosts $costs): View
    {
        $this->check($r);
        $b = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $r->input('search'));
        $lots = MaterialLot::query()->where('business_id', $b->id)->whereHas('workflow', fn ($q) => $q->where('posting_status', 'posted'))->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderByDesc('id')->paginate(30, ['*'], 'lot_page')->withQueryString();
        $states = $lots->mapWithKeys(fn ($lot) => [$lot->id => $costs->materialState($lot)]);
        $batches = StockBatch::query()->where('business_id', $b->id)->with('product')->when($search !== '', fn ($q) => $q->where('batch_code', 'like', '%'.$search.'%'))->latest('id')->paginate(40, ['*'], 'batch_page')->withQueryString();
        $expenses = FinanceOperation::query()->where('business_id', $b->id)->where('posting_status', 'posted')->whereIn('kind', ['expense', 'staff_expense', 'paid_expense'])->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')))->latest('id')->paginate(35, ['*'], 'expense_page')->withQueryString();
        $payrolls = FinancePayrollRun::query()->where('business_id', $b->id)->where('posting_status', 'posted')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->paginate(35, ['*'], 'payroll_page')->withQueryString();
        $workflows = FinanceWorkflow::query()->where('business_id', $b->id)->whereIn('kind', FinanceProductionCosts::KINDS)->latest('id')->paginate(25, ['*'], 'cost_page')->withQueryString();
        $issues = FinanceWorkflow::query()->where('business_id', $b->id)->where('kind', 'material_issue')->where('posting_status', 'posted')->latest('id')->paginate(35, ['*'], 'issue_page')->withQueryString();
        $releasable = FinanceWorkflow::query()->where('business_id', $b->id)->whereIn('kind', ['material_receipt', 'batch_cost'])->where('posting_status', 'posted')->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->paginate(35, ['*'], 'release_page')->withQueryString();
        $canCreate = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $business = $b;

        return view('finance.production-costs', compact('business', 'lots', 'states', 'batches', 'expenses', 'payrolls', 'workflows', 'issues', 'canCreate', 'releasable'));
    }

    public function store(Request $r, FinanceProductionCosts $costs): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['kind' => ['required', Rule::in(FinanceProductionCosts::KINDS)], 'event_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($b->timezone)->toDateString()], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'external_reference' => ['required', 'string', 'max:150'], 'request_key' => ['required', 'uuid'], 'source_type' => ['required_if:kind,material_receipt,batch_cost', 'nullable', 'in:expense,payroll'], 'source_id' => ['required_if:kind,material_receipt,batch_cost', 'nullable', 'integer', 'min:1'], 'source_account' => ['required_if:kind,material_receipt,batch_cost', 'nullable', 'in:general_expense,payroll_expense,nssf_employer_expense'], 'base_amount' => ['required_if:kind,material_receipt,batch_cost', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'batch_id' => ['required_if:kind,batch_cost,material_issue,material_return', 'nullable', 'integer', 'min:1'], 'material_lot_id' => ['required_if:kind,material_issue,material_return', 'nullable', 'integer', 'min:1'], 'quantity' => ['required_if:kind,material_receipt,material_issue,material_return', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'], 'name' => ['required_if:kind,material_receipt', 'nullable', 'string', 'max:150'], 'unit' => ['required_if:kind,material_receipt', 'nullable', 'string', 'max:40'], 'location' => ['required_if:kind,material_receipt', 'nullable', 'string', 'max:150'], 'original_workflow_id' => ['required_if:kind,material_return,cost_release', 'nullable', 'integer', 'min:1']]);
        $flow = $costs->prepare($b, $data['kind'], $data, $r->user()->id);

        return redirect()->route('finance.treasury.show', $flow->id)->with('status', 'Cost prepared for independent review. Stock and cost balances change together only after posting.');
    }
}
