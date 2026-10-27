<?php

namespace App\Http\Controllers;

use App\Models\AdvanceRequest;
use App\Models\Customer;
use App\Models\ExportShipment;
use App\Models\MarketAccountability;
use App\Models\MarketTrip;
use App\Models\PartnerFinanceTerm;
use App\Models\StaffPerson;
use App\Models\Supplier;
use App\Models\TradeCollectionCase;
use App\Services\FinanceDocuments;
use App\Services\FinanceTradeControls;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceTradeControlController extends Controller
{
    private function check(Request $r, string $permission = 'finance.view'): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);
    }

    public function index(Request $r): View
    {
        $this->check($r);
        $business = $r->attributes->get('activeBusiness');
        $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) $r->input('search'));
        $people = StaffPerson::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->paginate(35, ['*'], 'people_page')->withQueryString();
        $customers = Customer::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->paginate(35, ['*'], 'customer_page')->withQueryString();
        $suppliers = Supplier::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->paginate(35, ['*'], 'supplier_page')->withQueryString();
        $cases = TradeCollectionCase::query()->where('business_id', $business->id)->with('person', 'invoice', 'bill')->orderBy('status')->orderBy('next_action_on')->orderBy('id')->paginate(25, ['*'], 'case_page')->withQueryString();
        $documents = [];
        foreach ($cases as $case) {
            $documents[$case->id] = $case->invoice ?? $case->bill;
        }$terms = PartnerFinanceTerm::query()->where('business_id', $business->id)->with('customer', 'supplier')->orderByDesc('effective_on')->orderByDesc('id')->paginate(25, ['*'], 'terms_page')->withQueryString();
        $requests = AdvanceRequest::query()->where('business_id', $business->id)->with('person', 'supplier', 'trip')->orderByDesc('id')->paginate(25, ['*'], 'advance_page')->withQueryString();
        $trips = MarketTrip::query()->where('business_id', $business->id)->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->with('person')->latest('id')->paginate(25, ['*'], 'trip_page')->withQueryString();
        $closures = MarketAccountability::query()->where('business_id', $business->id)->with('trip')->orderByDesc('id')->paginate(20, ['*'], 'closure_page')->withQueryString();
        $shipments = ExportShipment::query()->where('business_id', $business->id)->whereIn('status', ['draft', 'ready'])->when($search !== '', fn ($q) => $q->where('reference', 'like', '%'.$search.'%'))->latest('id')->paginate(30, ['*'], 'shipment_page')->withQueryString();
        $invoices = app(FinanceDocuments::class)->outstandingQuery($business, true)->latest('id')->paginate(30, ['*'], 'invoice_page')->withQueryString();
        $bills = app(FinanceDocuments::class)->outstandingQuery($business, false)->latest('id')->paginate(30, ['*'], 'bill_page')->withQueryString();
        $canCreate = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $canReview = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.approve');

        return view('finance.trade.index', compact('business', 'people', 'customers', 'suppliers', 'cases', 'documents', 'terms', 'requests', 'trips', 'closures', 'shipments', 'invoices', 'bills', 'canCreate', 'canReview'));
    }

    public function terms(Request $r, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['customer_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'payment_days' => ['required', 'integer', 'between:0,3650'], 'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($business->timezone)->toDateString()], 'agreement' => ['required', 'string', 'min:20', 'max:3000']]);
        $controls->saveTerms($business, $data, $r->user()->id);

        return back()->with('status', 'New agreed terms recorded. Earlier issued documents retain their original dates and amounts.');
    }

    public function followup(Request $r, string $type, int $document, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $data = $r->validate(['staff_person_id' => ['nullable', 'integer', 'min:1'], 'status' => ['required', 'in:open,disputed,promised,resolved'], 'next_action_on' => ['nullable', 'date_format:Y-m-d'], 'promised_on' => ['required_if:status,promised', 'nullable', 'date_format:Y-m-d'], 'disputed_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'note' => ['required', 'string', 'min:20', 'max:3000']]);
        $controls->followup($r->attributes->get('activeBusiness'), $type, $document, $data, $r->user()->id);

        return back()->with('status', 'Document follow-up updated with history. A dispute does not write off debt.');
    }

    public function reminders(Request $r, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $controls->remind($r->attributes->get('activeBusiness'));

        return back()->with('status', 'Internal due-payment reminders refreshed from posted balances. No external messages were sent.');
    }

    public function requestAdvance(Request $r, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.documents.create');
        $business = $r->attributes->get('activeBusiness');
        $data = $r->validate(['staff_person_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'market_trip_id' => ['nullable', 'integer', 'min:1'], 'currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'needed_on' => ['required', 'date_format:Y-m-d'], 'accountable_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:needed_on'], 'reason' => ['required', 'string', 'min:20', 'max:3000'], 'request_key' => ['required', 'uuid']]);
        $controls->requestAdvance($business, $data, $r->user()->id);

        return back()->with('status', 'Advance requested. Separate approval authorises a limit; recording and posting the actual payment remain separate.');
    }

    public function reviewAdvance(Request $r, int $advance, string $decision, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['note' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $row = AdvanceRequest::query()->where('business_id', $business->id)->findOrFail($advance);
        $controls->reviewAdvance($business, $row, $decision === 'approve', $data['note'], $r->user()->id);

        return back()->with('status', 'Advance request reviewed. Actual cash still needs its money record.');
    }

    public function cancelAdvance(Request $r, int $advance, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $controls->cancelAdvance($business, AdvanceRequest::query()->where('business_id', $business->id)->findOrFail($advance), $data['reason'], $r->user()->id);

        return back()->with('status', 'Unused advance authority cancelled. Existing disbursements still need accountability.');
    }

    public function advanceLimit(Request $r, int $person, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $data = $r->validate(['limit' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $row = StaffPerson::query()->where('business_id', $business->id)->findOrFail($person);
        $controls->setAdvanceLimit($business, $row, $data['limit'], $data['reason'], $r->user()->id);

        return back()->with('status', 'Outstanding staff-advance limit updated with audit evidence.');
    }

    public function closeMarket(Request $r, int $trip, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $row = MarketTrip::query()->where('business_id', $business->id)->findOrFail($trip);
        $controls->closeMarket($business, $row, $data['reason'], $r->user()->id);

        return back()->with('status', 'Market accountability closed with cash, receipts, bills and any remaining payables identified.');
    }

    public function reopenMarket(Request $r, int $accountability, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.reviews.approve');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        $row = MarketAccountability::query()->where('business_id', $business->id)->findOrFail($accountability);
        $controls->reopenMarket($business, $row, $data['reason'], $r->user()->id);

        return back()->with('status', 'Market accountability reopened with its original closure retained.');
    }

    public function creditException(Request $r, int $shipment, FinanceTradeControls $controls): RedirectResponse
    {
        $this->check($r, 'finance.cash.manage');
        $business = $r->attributes->get('activeBusiness');
        $row = ExportShipment::query()->where('business_id', $business->id)->findOrFail($shipment);
        $data = $r->validate(['currency' => ['required', app(BusinessCurrencies::class)->rule($business)], 'exposure_limit' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'], 'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now($business->timezone)->toDateString()], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $controls->creditException($business, $row, $data, $r->user()->id);

        return back()->with('status', 'Shipment-specific credit exception recorded. Its approver cannot use it to issue their own invoice.');
    }
}
