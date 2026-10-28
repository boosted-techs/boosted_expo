<?php

namespace App\Http\Controllers;

use App\Models\InvoiceAccountingHandoff;
use App\Support\BusinessPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceAccountingHandoffController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'), 'finance.handoff.view'), 403);
        $b = $request->attributes->get('activeBusiness')->id;
        $handoffs = InvoiceAccountingHandoff::query()->where('business_id', $b)
            ->with(['invoice.customer', 'invoice.order', 'invoice.shipment'])
            ->latest()->workspacePaginate(30);
        $byCurrency = [];
        foreach (InvoiceAccountingHandoff::query()->where('business_id', $b)
            ->where('posting_status', 'awaiting_accounting_policy')
            ->select(['currency', 'commercial_amount'])->cursor() as $entry) {
            $byCurrency[$entry->currency] = ($byCurrency[$entry->currency] ?? BigDecimal::zero())
                ->plus((string) $entry->commercial_amount);
        }
        foreach ($byCurrency as $currency => $amount) {
            $byCurrency[$currency] = $amount->toScale(2, RoundingMode::HalfUp);
        }
        ksort($byCurrency);

        return view('sales.invoices.accounting-handoff', compact('handoffs', 'byCurrency'));
    }
}
