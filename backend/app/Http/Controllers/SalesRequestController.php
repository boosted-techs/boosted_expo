<?php

namespace App\Http\Controllers;

use App\Models\BusinessDestination;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesRequest;
use App\Services\SalesWorkflow;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesRequestController extends Controller
{
    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeAction($request, 'sales.view');
        $b = $request->attributes->get('activeBusiness')->id;
        $requests = SalesRequest::query()->where('business_id', $b)
            ->with('customer')->withCount('quotations')->latest()->workspacePaginate(20);
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'sales.manage');

        return view('sales.requests.index', compact('requests', 'canManage'));
    }

    public function create(Request $request): View
    {
        $this->authorizeAction($request, 'sales.manage');
        $b = $request->attributes->get('activeBusiness')->id;
        $customers = Customer::query()->where('business_id', $b)->where('active', true)->orderBy('name')->get();
        $products = Product::query()->where('business_id', $b)->where('active', true)->orderBy('name')->get();

        $destinations = BusinessDestination::where('business_id', $b)->where('active', true)->orderBy('name')->get();

        return view('sales.requests.create', compact('customers', 'products', 'destinations'));
    }

    public function store(Request $request, SalesWorkflow $sales): RedirectResponse
    {
        $this->authorizeAction($request, 'sales.manage');
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'required_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'destination_id' => ['nullable', 'integer'],
            'buyer_reference' => ['nullable', 'string', 'max:120'], 'submission_key' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
        ]);
        $record = $sales->createRequest($request->attributes->get('activeBusiness')->id,
            $data, $request->user()->id);

        return redirect()->route('sales-requests.show', $record->id)->with('status',
            'Customer request recorded. Prepare a quotation to set pricing.');
    }

    public function show(Request $request, int $record): View
    {
        $this->authorizeAction($request, 'sales.view');
        $record = SalesRequest::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->with(['customer', 'items.product', 'quotations.lines', 'order'])->findOrFail($record);
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'sales.manage');

        $activity = $request->validate(['activity' => ['nullable', Rule::in(['approval', 'quotes'])]])['activity'] ?? null;
        if ($activity === 'approval') {
            abort_unless($canManage && ! $record->order, 403);
        }

        return view($activity ? 'sales.requests.'.$activity : 'sales.requests.show', compact('record', 'canManage'));
    }

    public function draft(Request $request, int $record, SalesWorkflow $sales): RedirectResponse
    {
        $this->authorizeAction($request, 'sales.manage');
        $b = $request->attributes->get('activeBusiness')->id;
        SalesRequest::query()->where('business_id', $b)->findOrFail($record);
        $quote = $sales->draft($b, $record, $request->user()->id);

        return redirect()->route('sales-quotes.show', $quote->id)
            ->with('status', 'Quotation draft opened. Review prices before issuing.');
    }
}
