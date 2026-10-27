<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\InviteBusinessUser;
use App\Support\BusinessCurrencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformBusinessController extends Controller
{
    public function index(): View
    {
        $businesses = Business::query()->latest()->paginate(20);

        return view('platform.businesses', compact('businesses'));
    }

    public function store(Request $request, InviteBusinessUser $invites): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code'))), 'base_currency' => strtoupper(trim((string) $request->input('base_currency')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('businesses', 'code')],
            'country_code' => ['required', 'alpha', 'size:2'],
            'base_currency' => ['required', 'string', Rule::in(array_keys(app(BusinessCurrencies::class)->catalogue()))],
            'timezone' => ['required', 'timezone'],
            'default_qr_level' => ['required', Rule::in(['product', 'batch', 'carton'])],
            'owner_email' => ['required', 'email', 'max:255'],
        ]);

        $business = DB::transaction(fn () => Business::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'country_code' => strtoupper($data['country_code']),
            'base_currency' => strtoupper($data['base_currency']),
            'timezone' => $data['timezone'],
            'default_qr_level' => $data['default_qr_level'],
        ]));

        $invites->send($business, $data['owner_email'], 'owner', $request->user()->id);
        $message = config('mail.default') === 'log'
            ? "Created {$business->name}. The invitation was written to the development mail log, not delivered."
            : "Created {$business->name} and sent an owner invitation.";

        return redirect()->route('platform.businesses.index')->with('status', $message);
    }
}
