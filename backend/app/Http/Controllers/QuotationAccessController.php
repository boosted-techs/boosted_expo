<?php

namespace App\Http\Controllers;

use App\Models\QuotationAccess;
use App\Models\SalesQuotation;
use App\Services\OrderOperations;
use App\Services\QuotationAccessService;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class QuotationAccessController extends Controller
{
    public function create(Request $request, int $quote, QuotationAccessService $links): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage'), 403);
        $quote = SalesQuotation::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($quote);
        $data = $request->validate(['recipient_email' => ['required', 'email', 'max:255']]);
        $token = $links->create($quote, $data['recipient_email'], $request->user()->id);

        return back()->with('quotation_url', route('buyer-quotation.show', $token))->with('status', 'Private buyer link created. Share it with the intended recipient. It replaces any previous link for this revision.');
    }

    public function revoke(Request $request, int $access): RedirectResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($request->attributes->get('membership'), 'sales.manage'), 403);
        $access = QuotationAccess::query()->where('business_id', $request->attributes->get('activeBusiness')->id)->findOrFail($access);
        DB::transaction(function () use ($access) {
            app(OrderOperations::class)->lockForWork($access->business_id, 'sales');
            $access->update(['revoked_at' => now()]);
        }, 3);

        return back()->with('status', 'Buyer link withdrawn. Its decision history remains available.');
    }

    public function show(string $token, QuotationAccessService $links): View
    {
        $data = $links->resolve($token);

        return view('sales.quotes.buyer', [...$data, 'token' => $token]);
    }

    public function document(string $token, QuotationAccessService $links): Response
    {
        $data = $links->resolve($token);
        $quote = $data['quote'];
        abort_unless($quote->html_snapshot && hash_equals($quote->sha256, hash('sha256', $quote->html_snapshot)), 422, 'The issued quotation snapshot needs review.');

        return response($quote->html_snapshot, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function decide(Request $request, string $token, QuotationAccessService $links): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected'])], 'buyer_name' => ['required', 'string', 'min:2', 'max:120'], 'buyer_reference' => ['required', 'string', 'min:5', 'max:160']]);
        $links->decide($token,$data);

        return back()->with('status','Your quotation decision has been recorded.');
    }
}
