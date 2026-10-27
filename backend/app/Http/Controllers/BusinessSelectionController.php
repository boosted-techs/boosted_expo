<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessSelectionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $memberships = $request->user()->memberships()
            ->where('active', true)
            ->whereHas('business', fn ($q) => $q->where('is_active', true))
            ->with('business')->get();

        if ($memberships->isEmpty() && $request->user()->is_platform_admin) {
            return redirect()->route('platform.overview');
        }

        return view('businesses.choose', compact('memberships'));
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate(['business_id' => ['required', 'integer']]);
        $membership = BusinessMembership::query()
            ->where('business_id', $input['business_id'])
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->whereHas('business', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $request->session()->put('active_business_id', $membership->business_id);

        return redirect()->route('workspace');
    }
}
