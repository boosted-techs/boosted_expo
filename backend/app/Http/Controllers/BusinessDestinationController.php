<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessDestination;
use App\Services\PlatformAccess;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BusinessDestinationController extends Controller
{
    private function check(Request $r): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'sales.manage'), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r);
        $destinations = BusinessDestination::where('business_id', $r->attributes->get('activeBusiness')->id)->orderBy('name')->workspacePaginate(25);

        return view('destinations.index', compact('destinations'));
    }

    public function create(Request $r): View
    {
        $this->check($r);

        return view('destinations.create');
    }

    public function store(Request $r): RedirectResponse|JsonResponse
    {
        $this->check($r);
        $b = $r->attributes->get('activeBusiness');
        $data = $r->validate(['name' => ['required', 'string', 'min:2', 'max:160'], 'country_code' => ['nullable', Rule::in(array_keys(config('countries')))], 'city' => ['nullable', 'string', 'max:100'], 'location' => ['nullable', 'string', 'max:500']]);
        $key = mb_strtolower(trim($data['name']));
        $record = DB::transaction(function () use ($data, $key, $b, $r) {
            Business::whereKey($b->id)->lockForUpdate()->firstOrFail();
            if (BusinessDestination::where('business_id', $b->id)->where('name_key', $key)->exists()) {
                throw ValidationException::withMessages(['name' => 'This destination is already available.']);
            }$record = BusinessDestination::create([...$data, 'business_id' => $b->id, 'name_key' => $key, 'active' => true]);
            app(PlatformAccess::class)->audit($b->id, $r->user()->id, 'destination.created', 'Destination added.', [], $record->getAttributes());

            return $record;
        });

        return $r->expectsJson() ? response()->json(['message' => 'Destination added.', 'selection' => ['value' => $record->id, 'label' => $record->name]]) : redirect()->route('destinations.index')->with('status', 'Destination added.');
    }
}
