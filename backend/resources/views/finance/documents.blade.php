@extends('layouts.console')
@section('title','Finance Documents')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCIAL SOURCE DOCUMENTS</span><h1>Supplier bills, payments & shipment costs</h1><p>Create financial documents from actual purchase receipts, commercial invoices and recorded export expenses.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Posting approvals</a><a class="secondary-button" href="{{ route('finance.rates') }}">Historical FX rates</a></div>
<div class="notice"><span>●</span><div><strong>Source records are not yet ledger postings</strong><p>Supplier bills represent accepted produce only, not automatically every quantity ordered. Payment entries require an external reference but are not verified against a bank or mobile-money provider. Each item must be independently approved and posted.</p></div></div>
@if($canCreate)
<div class="split-panels">
<section class="section-card"><h2>Supplier bill from received produce</h2>
<p class="muted">The calculated supplier bill uses the accepted quantities and agreed purchase-order unit price. You can bill part of the accepted produce. Pending bills reserve their quantities.</p>
@include('finance._record-search', ['field' => 'purchase_search', 'page' => 'purchase_page', 'label' => 'Find unbilled purchase by reference or supplier'])
@if($purchases->isEmpty())<p class="empty-msg">No accepted, unbilled produce available.</p>@endif
@foreach($purchases as $purchase)
<details class="finance-details"><summary><strong>{{ $purchase->reference }}</strong> · {{ $purchase->product->name }} · {{ $purchase->currency }} {{ $purchase->estimated_total }}</summary>
<div class="form-hint">Vendor: {{ $purchase->supplier?->name ?: $purchase->vendor_name ?: 'Unregistered vendor' }} · Ordered {{ $purchase->quantity }} {{ $purchase->product->base_unit }}</div>
<form method="POST" action="{{ route('finance.bills.store',$purchase->id) }}" class="form-stack">@csrf
<label class="field"><span>Supplier document / invoice reference</span><input name="vendor_invoice_reference" maxlength="120" required placeholder="e.g. Supplier bill 2044"></label>
<label class="field"><span>Supplier invoice date</span><input name="vendor_invoice_date" type="date" required max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></label>
<label class="field"><span>Quantity to bill (blank = all accepted unbilled produce)</span><input name="accepted_quantity" type="number" min="0.001" step="0.001"></label>
<label class="field"><span>Agreed payment due date (optional)</span><input name="due_on" type="date"></label>
<label class="field"><span>Notes (optional)</span><textarea name="notes" rows="2" maxlength="2000"></textarea></label>
<button class="secondary-button">Prepare supplier bill</button>
</form></details>
@endforeach
{{ $purchases->links() }}
</section>
<section class="section-card"><h2>Customer receipt (manual evidence)</h2>
<p class="muted">Allocate a receipt to one already-posted commercial invoice. Each amount must use that invoice's currency.</p>
@include('finance._record-search', ['field' => 'invoice_search', 'page' => 'invoice_page', 'label' => 'Find outstanding invoice by reference or customer'])
{{ $invoices->links() }}
<form method="POST" action="{{ route('finance.payments.store') }}" class="form-stack">@csrf
<input type="hidden" name="direction" value="in"><input type="hidden" name="idempotency_key" value="{{ $paymentKey }}">
<label class="field"><span>Posted invoice / outstanding balance</span><select name="commercial_invoice_id" required><option value="">Choose an invoice</option>
@foreach($invoices as $invoice)@if(\Brick\Math\BigDecimal::of($invoiceBalances[$invoice->id])->isGreaterThan(0))
<option value="{{ $invoice->id }}">{{ $invoice->reference }} · {{ $invoice->customer->name }} · {{ $invoice->currency }} {{ $invoiceBalances[$invoice->id] }} available</option>@endif @endforeach</select></label>
<div class="form-columns"><label class="field"><span>Amount in invoice currency</span><input type="number" name="amount" step=".01" min=".01" required></label>
<label class="field"><span>Recorded into</span><select name="cash_account_key"><option value="bank">Bank / electronic</option><option value="cash">Cash</option></select></label></div>
@if($cashCustodies->isNotEmpty())<label class="field"><span>Named cash float (cash payments only)</span><select name="cash_custody_id"><option value="">Use bank / original cash choice</option>@foreach($cashCustodies as $custody)<option value="{{ $custody->id }}">{{ $custody->name }} · {{ $custody->currency }}</option>@endforeach</select></label>@endif
@if($bankProfiles->isNotEmpty())
<label class="field"><span>Named bank ledger (when using bank/electronic)</span>
<select name="bank_profile_id"><option value="">Cash only / legacy bank not available</option>
@foreach($bankProfiles as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->institution }} · {{ $bank->currency }}</option>@endforeach
</select><small>Bank transfer? Choose its actual named bank. Leave blank only when recording cash. Currency must match the invoice, supplier bill or expense.</small></label>
@endif
<label class="field"><span>Date funds were received</span><input type="date" name="payment_on" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></label>
<label class="field"><span>Bank, mobile money or external reference</span><input name="external_reference" required minlength="3" maxlength="150"></label>
<label class="field"><span>Notes (optional)</span><textarea name="notes" rows="2" maxlength="2000"></textarea></label>
<button class="primary-button">Record receipt for review</button></form>
</section>
<section class="section-card"><h2>Supplier payment (manual evidence)</h2>
<p class="muted">Allocate a payment to an approved and posted supplier bill.</p>
@include('finance._record-search', ['field' => 'bill_search', 'page' => 'payable_page', 'label' => 'Find outstanding bill by reference or supplier'])
{{ $payableBills->links() }}
<form method="POST" action="{{ route('finance.payments.store') }}" class="form-stack">@csrf
<input type="hidden" name="direction" value="out"><input type="hidden" name="idempotency_key" value="{{ $supplierPaymentKey }}">
<label class="field"><span>Posted supplier bill / balance</span><select name="supplier_bill_id" required><option value="">Choose bill</option>
@foreach($payableBills as $bill)@if(\Brick\Math\BigDecimal::of($billBalances[$bill->id])->isGreaterThan(0))
<option value="{{ $bill->id }}">{{ $bill->reference }} · {{ $bill->currency }} {{ $billBalances[$bill->id] }} payable</option>@endif @endforeach</select></label>
<div class="form-columns"><label class="field"><span>Amount in bill currency</span><input name="amount" type="number" step=".01" min=".01" required></label>
<label class="field"><span>Paid from</span><select name="cash_account_key"><option value="bank">Bank / electronic</option><option value="cash">Cash</option></select></label></div>
@if($cashCustodies->isNotEmpty())<label class="field"><span>Named cash float (cash payments only)</span><select name="cash_custody_id"><option value="">Use bank / original cash choice</option>@foreach($cashCustodies as $custody)<option value="{{ $custody->id }}">{{ $custody->name }} · {{ $custody->currency }}</option>@endforeach</select></label>@endif
@if($bankProfiles->isNotEmpty())
<label class="field"><span>Named bank ledger (when using bank/electronic)</span>
<select name="bank_profile_id"><option value="">Cash only / legacy bank not available</option>
@foreach($bankProfiles as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->institution }} · {{ $bank->currency }}</option>@endforeach
</select><small>Bank transfer? Choose its actual named bank. Leave blank only when recording cash. Currency must match the invoice, supplier bill or expense.</small></label>
@endif
<label class="field"><span>Date of payment</span><input name="payment_on" type="date" required max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></label>
<label class="field"><span>Bank or external payment reference</span><input name="external_reference" maxlength="150" minlength="3" required></label>
<button class="primary-button">Record supplier payment for review</button></form>
</section>
<section class="section-card"><h2>Record a paid shipment cost</h2><p class="muted">Use for expenses you have supporting payment evidence for. This is a manually recorded payment, not bank feed confirmation.</p>
@include('finance._record-search', ['field' => 'shipment_search', 'page' => 'shipment_page', 'label' => 'Find shipment by reference or AWB'])
{{ $shipments->links() }}
<form method="POST" action="{{ route('finance.costs.store') }}" class="form-stack">@csrf<input type="hidden" name="idempotency_key" value="{{ (string)Illuminate\Support\Str::uuid() }}">
<label class="field"><span>Transport leg (optional, must match shipment)</span><select name="transport_leg_id"><option value="">Whole shipment / no specific leg</option>@foreach($transportLegs as $leg)<option value="{{ $leg->id }}">{{ $leg->shipment->reference }} · Leg {{ $leg->sequence }} · {{ $leg->partner_snapshot['name'] }} · {{ $leg->origin }} → {{ $leg->destination }}</option>@endforeach</select></label>
<label class="field"><span>Shipment</span><select name="shipment_id" required><option value="">Choose shipment</option>@foreach($shipments as $shipment)<option value="{{ $shipment->id }}">{{ $shipment->reference }} · {{ $shipment->destination_country }}</option>@endforeach</select></label>
<div class="form-columns"><label class="field"><span>Cost category</span><select name="category">
@foreach(['freight'=>'Air freight','packing_material'=>'Packing materials','ground_transport'=>'Ground transport','handling'=>'Cargo handling','airline_fees'=>'Airline charges','inspection'=>'Inspection','other'=>'Other'] as $key=>$name)
<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
<label class="field"><span>Currency</span><x-currency-select :value="old('currency', $business->base_currency)" /></label></div>
<label class="field"><span>Expense description</span><input name="description" maxlength="250" minlength="5" required></label>
<div class="form-columns"><label class="field"><span>Paid amount</span><input name="amount" type="number" min=".01" step=".01" required></label>
<label class="field"><span>Paid via</span><select name="cash_account_key"><option value="bank">Bank / electronic</option><option value="cash">Cash</option></select></label></div>
@if($cashCustodies->isNotEmpty())<label class="field"><span>Named cash float (cash payments only)</span><select name="cash_custody_id"><option value="">Use bank / original cash choice</option>@foreach($cashCustodies as $custody)<option value="{{ $custody->id }}">{{ $custody->name }} · {{ $custody->currency }}</option>@endforeach</select></label>@endif
@if($bankProfiles->isNotEmpty())
<label class="field"><span>Named bank ledger (when using bank/electronic)</span>
<select name="bank_profile_id"><option value="">Cash only / legacy bank not available</option>
@foreach($bankProfiles as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->institution }} · {{ $bank->currency }}</option>@endforeach
</select><small>Bank transfer? Choose its actual named bank. Leave blank only when recording cash. Currency must match the invoice, supplier bill or expense.</small></label>
@endif
<label class="field"><span>Payment date</span><input name="paid_on" type="date" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></label>
<label class="field"><span>External voucher / proof reference</span><input name="external_reference" minlength="3" maxlength="150" required></label>
<button class="secondary-button">Record shipment cost for review</button></form>
</section></div>
@endif
<section class="section-card"><h2>Supplier bills</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Bill</th><th>Purchase</th><th>Supplier document</th><th>Accepted</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@forelse($bills as $bill)<tr><td><strong>{{ $bill->reference }}</strong></td><td>{{ $bill->purchase->reference }}</td><td>{{ $bill->vendor_invoice_reference }}</td>
<td>{{ $bill->accepted_quantity }} {{ $bill->purchase->product?->base_unit }}</td><td>{{ $bill->currency }} {{ $bill->total }}</td><td><span class="tiny-pill">{{ $bill->posting_status==='posted'?'Posted':'Unposted' }}</span></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No supplier bills recorded yet.</td></tr>@endforelse
</tbody></table></div>{{ $bills->links() }}</section>
<section class="section-card"><h2>Recorded receipts & payments</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Direction</th><th>Related document</th><th>Amount</th><th>External reference</th><th>Status</th></tr></thead><tbody>
@forelse($payments as $payment)<tr><td><strong>{{ $payment->reference }}</strong></td><td>{{ $payment->direction==='in'?'Customer receipt':'Supplier payment' }}</td>
<td>{{ $payment->invoice?->reference ?: $payment->bill?->reference }}</td><td>{{ $payment->currency }} {{ $payment->amount }}</td>
<td>{{ $payment->external_reference }}</td><td>{{ ucfirst($payment->status) }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No recorded payments yet.</td></tr>@endforelse</tbody></table></div>{{ $payments->links() }}</section>
<section class="section-card"><h2>Shipment costs</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Cost</th><th>Shipment</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@forelse($costs as $cost)<tr><td>{{ $cost->reference }}</td><td>{{ $cost->shipment->reference }}</td><td>{{ ucwords(str_replace('_',' ',$cost->category)) }}</td>
<td>{{ $cost->description }}</td><td>{{ $cost->currency }} {{ $cost->amount }}</td><td>{{ ucfirst($cost->status) }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No shipment costs recorded.</td></tr>@endforelse</tbody></table></div>{{ $costs->links() }}</section>
@endsection
