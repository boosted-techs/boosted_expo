<?php

namespace App\Http\Controllers;

use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BusinessSettingsController extends Controller
{
    public function index(Request $request, BusinessPermissions $access): View
    {
        abort_unless($access->allows($request->attributes->get('membership'), 'business.settings'), 403);
        $business = $request->attributes->get('activeBusiness');

        $policy=\App\Models\BusinessOperatingPolicy::query()
            ->where('business_id',$business->id)->first();
        $canChangePolicy=$request->attributes->get('membership')->role==='owner';
        return view('businesses.settings', compact('business','policy','canChangePolicy'));
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