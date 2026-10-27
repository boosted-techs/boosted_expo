<?php

namespace App\Http\Controllers;

use App\Models\AssetActivity;
use App\Models\BusinessAsset;
use App\Models\CashCustody;
use App\Models\FinanceBankProfile;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceOperation;
use App\Models\FinanceRegisterEntry;
use App\Models\StaffPerson;
use App\Services\AssetRegister;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AssetRegisterController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $filters = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'ownership' => ['nullable', Rule::in(['owned', 'hired', 'third_party'])]]);
        $assets = BusinessAsset::query()->where('business_id', $business->id)->with('person')->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$v.'%')->orWhere('asset_code', 'like', '%'.$v.'%')->orWhere('serial_number', 'like', '%'.$v.'%')->orWhere('location', 'like', '%'.$v.'%')))->when($filters['ownership'] ?? null, fn ($q, $v) => $q->where('ownership', $v))->orderBy('asset_code')->paginate(25)->withQueryString();
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $states = $assets->mapWithKeys(fn ($a) => [$a->id => app(AssetRegister::class)->state($a)]);

        return view('finance.assets.index', compact('business', 'assets', 'canManage', 'states'));
    }

    public function store(Request $r, AssetRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $data = $r->validate(['asset_code' => ['required', 'string', 'min:2', 'max:65'], 'name' => ['required', 'string', 'min:2', 'max:150'], 'category' => ['required', 'string', 'max:80'], 'ownership' => ['required', 'in:owned,hired,third_party'], 'serial_number' => ['nullable', 'string', 'max:120'], 'purchase_reference' => ['nullable', 'string', 'max:150'], 'location' => ['required', 'string', 'max:150'], 'condition' => ['required', 'in:good,worn,damaged,lost'], 'useful_life_months' => ['nullable', 'integer', 'between:1,600'], 'residual_base' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'depreciation_start_on' => ['required_with:useful_life_months', 'nullable', 'date_format:Y-m-d'], 'maintenance_due_on' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:3000']]);
        $asset = $register->create($r->attributes->get('activeBusiness'), $data, $r->user()->id);

        return redirect()->route('finance.assets.show', $asset->id)->with('status', 'Equipment registered. Registration alone does not create an asset value or a payment.');
    }

    public function show(Request $r, int $asset): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $asset = BusinessAsset::query()->where('business_id', $business->id)->with('person')->findOrFail($asset);
        $state = app(AssetRegister::class)->state($asset);
        $r->validate(['lookup' => ['nullable', 'string', 'max:120']]);
        $lookup = trim((string) $r->input('lookup'));
        $people = StaffPerson::query()->where('business_id', $business->id)->where('active', true)->when($lookup !== '', fn ($q) => $q->where('name', 'like', '%'.$lookup.'%'))->orderBy('name')->paginate(30, ['*'], 'people_page')->withQueryString();
        $expenses = FinanceOperation::query()->where('business_id', $business->id)->whereIn('kind', ['expense', 'paid_expense', 'staff_expense'])->when($lookup !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$lookup.'%')->orWhere('description', 'like', '%'.$lookup.'%')))->latest('id')->paginate(20, ['*'], 'expense_page')->withQueryString();
        $activities = AssetActivity::query()->where('business_id', $business->id)->where('asset_id', $asset->id)->with('expense')->latest('id')->paginate(20, ['*'], 'activity_page')->withQueryString();
        $entries = FinanceRegisterEntry::query()->where('business_id', $business->id)->where('asset_id', $asset->id)->latest('id')->paginate(20, ['*'], 'entry_page')->withQueryString();
        $rates = FinanceExchangeRate::query()->where('business_id', $business->id)->where('status', 'approved')->orderByDesc('effective_on')->get();
        $custodies = CashCustody::query()->where('business_id', $business->id)->where('active', true)->orderBy('name')->get();
        $banks = FinanceBankProfile::query()->where('business_id', $business->id)->where('active', true)->orderBy('label')->get();
        $canManage = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');

        return view('finance.assets.show', compact('business', 'asset', 'state', 'people', 'expenses', 'activities', 'entries', 'rates', 'banks', 'custodies', 'canManage'));
    }

    public function update(Request $r, int $asset, AssetRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $asset = BusinessAsset::query()->where('business_id', $business->id)->findOrFail($asset);
        $data = $r->validate(['asset_code' => ['required', 'string', 'min:2', 'max:65'], 'name' => ['required', 'string', 'min:2', 'max:150'], 'category' => ['required', 'string', 'max:80'], 'serial_number' => ['nullable', 'string', 'max:120'], 'purchase_reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:3000'], 'useful_life_months' => ['sometimes', 'nullable', 'integer', 'between:1,600'], 'residual_base' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'depreciation_start_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $register->correctProfile($business, $asset, $data, $r->user()->id);

        return back()->with('status', 'Equipment profile corrected with the previous values and reason retained in the business audit.');
    }

    public function activity(Request $r, int $asset, AssetRegister $register): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $asset = BusinessAsset::query()->where('business_id', $business->id)->findOrFail($asset);
        $data = $r->validate(['kind' => ['required', 'in:assign,return,condition,maintenance_start,maintenance_complete,maintenance_plan'], 'staff_person_id' => ['nullable', 'integer', 'min:1'], 'location' => ['required_if:kind,assign,return', 'nullable', 'string', 'max:150'], 'condition' => ['required_if:kind,condition,return,maintenance_complete', 'nullable', 'in:good,worn,damaged,lost'], 'maintenance_due_on' => ['nullable', 'date_format:Y-m-d'], 'expense_id' => ['nullable', 'integer', 'min:1'], 'event_on' => ['required', 'date_format:Y-m-d'], 'reference' => ['required', 'string', 'min:3', 'max:150'], 'note' => ['required', 'string', 'min:10', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $register->activity($business, $asset, $data, $r->user()->id);

        return back()->with('status', 'Asset custody / maintenance history updated. Linked expenses keep their original finance approval.');
    }
}
