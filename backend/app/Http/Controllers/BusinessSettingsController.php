<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessOperatingPolicy;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BusinessSettingsController extends Controller
{
    public function index(Request $request, BusinessPermissions $access): View
    {
        abort_unless($access->allows($request->attributes->get('membership'), 'business.settings'), 403);
        $business = $request->attributes->get('activeBusiness');

        $policy = BusinessOperatingPolicy::query()
            ->where('business_id', $business->id)->first();
        $canChangePolicy = $request->attributes->get('membership')->role === 'owner';
        $currencyCatalogue = app(BusinessCurrencies::class)->catalogue();
        $enabledCurrencies = app(BusinessCurrencies::class)->enabled($business);

        return view('businesses.settings', compact('business', 'policy', 'canChangePolicy', 'currencyCatalogue', 'enabledCurrencies'));
    }

    public function currencies(Request $request, BusinessCurrencies $currencies): RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->role === 'owner', 403);
        $business = $request->attributes->get('activeBusiness');
        $data = $request->validate([
            'enabled_currencies' => ['required', 'array', 'min:1', 'max:'.count($currencies->catalogue())],
            'enabled_currencies.*' => ['required', 'string', 'distinct', Rule::in(array_keys($currencies->catalogue()))],
        ]);
        if (! in_array($business->base_currency, $data['enabled_currencies'], true)) {
            throw ValidationException::withMessages(['enabled_currencies' => 'Keep the reporting currency enabled.']);
        }
        DB::transaction(function () use ($business, $data) {
            $record = Business::query()->lockForUpdate()->findOrFail($business->id);
            $record->update(['enabled_currencies' => array_values($data['enabled_currencies'])]);
        }, 3);

        return back()->with('status', 'Business currencies updated. Historical documents and exchange rates are unchanged.');
    }

    public function update(Request $request, BusinessPermissions $access): RedirectResponse
    {
        abort_unless($access->allows($request->attributes->get('membership'), 'business.settings'), 403);
        $data = $request->validate([
            'default_qr_level' => ['required', Rule::in(['product', 'batch', 'carton'])],
        ]);
        $request->attributes->get('activeBusiness')->update($data);

        return back()->with('status', 'Business QR preference updated.');
    }
}
