<?php

namespace App\Http\Controllers;

use App\Services\FinanceOwnerPosition;
use App\Support\BusinessPermissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancePositionController extends Controller
{
    public function index(Request $request, FinanceOwnerPosition $position): View
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'finance.reports.view'), 403);
        $data = $request->validate(['on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()], 'collection_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2']]);
        $business = $request->attributes->get('activeBusiness');
        $date = $data['on'] ?? now($business->timezone)->toDateString();
        $collectionPercent = $data['collection_percent'] ?? '100';
        $report = $position->report($business, $date, $collectionPercent);

        return view('finance.position', compact('business', 'date', 'collectionPercent', 'report'));
    }
}
