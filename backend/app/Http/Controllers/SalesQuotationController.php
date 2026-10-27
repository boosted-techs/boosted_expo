<?php

namespace App\Http\Controllers;

use App\Models\FinanceExchangeRate;
use App\Models\QuotationAccess;
use App\Models\SalesQuotation;
use App\Services\SalesWorkflow;
use App\Support\BusinessCurrencies;
use App\Support\BusinessPermissions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SalesQuotationController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    private function own(Request $request, int $id): SalesQuotation
    {
        return SalesQuotation::query()->where('business_id', $request->attributes
            ->get('activeBusiness')->id)->findOrFail($id);
    }

    public function show(Request $request, int $quote): View
    {
        $this->check($request, 'sales.view');
        $quote = $this->own($request, $quote)->load(['lines.product', 'salesRequest.customer', 'salesRequest.quotations', 'order']);
        $canManage = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'sales.manage');
        $canConfirm = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'sales.orders.confirm');

        $rates = FinanceExchangeRate::query()->where('business_id', $quote->business_id)->where('status', 'approved')->orderByDesc('effective_on')->get();
        $accesses = QuotationAccess::query()->where('business_id', $quote->business_id)->where('sales_quotation_id', $quote->id)->latest('id')->get();

        return view('sales.quotes.show', compact('quote', 'canManage', 'canConfirm', 'rates', 'accesses'));
    }

    public function update(Request $request, int $quote, SalesWorkflow $sales): RedirectResponse
    {
        $this->check($request, 'sales.manage');
        $quote = $this->own($request, $quote);
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $data = $request->validate([
            'currency' => ['required', 'string', app(BusinessCurrencies::class)->rule($request->attributes->get('activeBusiness'))],
            'currency_override_reason' => ['nullable', 'string', 'min:10', 'max:250'],
            'pricing_exchange_rate_id' => ['nullable', 'integer', 'min:1'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'incoterm' => ['nullable', 'in:EXW,FCA,CPT,CIP,DAP,DPU,DDP'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:'.now($request->attributes->get('activeBusiness')->timezone)->toDateString()],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'tax_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
        ]);
        $sales->updateDraft($quote, $data);

        return redirect()->route('sales-quotes.show', $quote->id)
            ->with('status', 'Draft saved. Issue when prices and terms are confirmed.');
    }

    public function issue(Request $request, int $quote, SalesWorkflow $sales): RedirectResponse
    {
        $this->check($request, 'sales.manage');
        $sales->issue($this->own($request, $quote), $request->user()->id);

        return back()->with('status',
            'Quotation issued and locked for sharing. Download it to send to the customer; no email was sent automatically.');
    }

    public function confirm(Request $request, int $quote, SalesWorkflow $sales): RedirectResponse
    {
        $this->check($request, 'sales.orders.confirm');
        $data = $request->validate([
            'buyer_confirmation' => ['required', 'string', 'min:5', 'max:160'],
        ]);
        $order = $sales->confirm($this->own($request, $quote), $data['buyer_confirmation'],
            $request->user()->id);

        return redirect()->route('sales-orders.show', $order->id)
            ->with('status', 'Customer quotation accepted and sales order confirmed. This does not create a payment or accounting entry.');
    }

    public function print(Request $request, int $quote): Response
    {
        $this->check($request, 'sales.view');
        $quote = $this->own($request, $quote);
        abort_unless($quote->status !== 'draft' && $quote->html_snapshot !== null, 422,
            'Issue the quotation before printing.');

        return response($quote->html_snapshot, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request, int $quote): Response
    {
        $html = $this->print($request, $quote)->getContent();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quotation-'.$quote.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
