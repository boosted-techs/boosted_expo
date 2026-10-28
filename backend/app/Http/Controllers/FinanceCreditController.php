<?php

namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\FinanceCreditNote;
use App\Models\FinancePayment;
use App\Services\FinanceCreditNotes;
use App\Support\BusinessPermissions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FinanceCreditController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), $permission), 403);
    }

    private function own(Request $request, int $id): FinanceCreditNote
    {
        return FinanceCreditNote::query()->where('business_id', $request->attributes
            ->get('activeBusiness')->id)->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->check($request, 'finance.credits.view');
        $business = $request->attributes->get('activeBusiness');
        $notes = FinanceCreditNote::query()->where('business_id', $business->id)
            ->with('invoice.customer')->latest()->workspacePaginate(20);
        $canPrepare = app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.credits.prepare');
        $invoices = CommercialInvoice::query()->where('business_id', $business->id)
            ->where('posting_status', 'posted')->with('customer')
            ->whereDoesntHave('creditNote')
            ->latest()->limit(75)->get();
        $withPayments = FinancePayment::query()->where('business_id', $business->id)
            ->whereIn('status', ['pending', 'posted'])
            ->whereIn('commercial_invoice_id', $invoices->pluck('id'))
            ->pluck('commercial_invoice_id')->all();
        $invoices = $invoices->filter(fn ($i) => ! in_array($i->id, $withPayments, true));

        return view('finance.credits', compact('business', 'notes', 'canPrepare', 'invoices'));
    }

    public function prepare(Request $request, int $invoice, FinanceCreditNotes $service): RedirectResponse
    {
        $this->check($request, 'finance.credits.prepare');
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $request->attributes->get('activeBusiness');
        $invoice = CommercialInvoice::query()->where('business_id', $business->id)->findOrFail($invoice);
        $note = $service->prepare($business, $invoice, $data['reason'], $request->user()->id);

        return redirect()->route('finance.credits.show', $note->id)->with('status',
            'Full credit correction prepared. It is not effective until independently approved and posted.');
    }

    public function show(Request $request, int $note): View
    {
        $this->check($request, 'finance.credits.view');
        $note = $this->own($request, $note)->load('invoice.customer');

        return view('finance.credit-detail', compact('note'));
    }

    public function print(Request $request, int $note): Response
    {
        $this->check($request, 'finance.credits.view');
        $note = $this->own($request, $note);
        abort_unless($note->posting_status === 'posted' && $note->html_snapshot !== null, 422,
            'The credit note must be independently approved and posted before download.');

        return response($note->html_snapshot, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; object-src 'none'",
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request, int $note): Response
    {
        $html = $this->print($request, $note)->getContent();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $renderer = new Dompdf($options);
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->setPaper('a4', 'portrait');
        $renderer->render();

        return response($renderer->output(),200,[
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="credit-note-'.$note.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
