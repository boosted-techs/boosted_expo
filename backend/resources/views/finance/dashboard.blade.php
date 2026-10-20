@extends('layouts.console')
@section('title','Finance & Ledger')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCIAL MANAGEMENT</span><h1>Finance and accounting</h1><p>Company chart of accounts, controlled postings, receivables, payables and source-currency reporting.</p></div><span class="tiny-pill">{{ $business->base_currency }} BASE</span></div>
<div class="notice"><span>●</span><div><strong>Controlled accounting — no automatic revenue posting</strong><p>Documents are entered first, then reviewed by a second authorised person and posted by a different person from the approver. Payments are manual records until posted; there is no live bank reconciliation. Income recognition requires review of the actual delivery terms and transfer evidence.</p></div></div>
<div class="form-actions" style="margin:18px 0">
<a class="secondary-button" href="{{ route('finance.documents') }}">Supplier bills, payments & expenses</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Approval & posting queue</a>
<a class="secondary-button" href="{{ route('finance.rates') }}">Exchange rates</a>
<a class="secondary-button" href="{{ route('shipments.index') }}">Shipment contribution reports</a>
</div>
@if($accounts->isEmpty())
<section class="section-card form-narrow"><h2>Activate financial accounts</h2>
<p class="muted">The financial chart is not set up for this business yet. An authorised business administrator must activate the standard export-accounting accounts before any posting.</p>
@if($canSetup)<form method="POST" action="{{ route('finance.setup') }}">@csrf<button class="primary-button">Activate standard chart of accounts</button></form>
@else<p class="form-hint">Ask your business owner or administrator to activate the financial chart.</p>@endif
</section>
@else
<div class="stats">
<article class="stat"><span>Draft/unposted commercial invoices</span><strong>{{ $unposted['invoices'] }}</strong><small>Not automatically recognised</small></article>
<article class="stat"><span>Unposted supplier bills</span><strong>{{ $unposted['supplier_bills'] }}</strong><small>Inventory and payable pending</small></article>
<article class="stat"><span>Pending payments & shipment costs</span><strong>{{ $unposted['payments']+$unposted['shipment_costs'] }}</strong><small>Manual evidence; unposted</small></article>
<article class="stat"><span>Open finance reviews</span><strong>{{ $pending }}</strong><small>Pending or approved for posting</small></article>
</div>
<section class="section-card"><h2>Trial balance · {{ $business->base_currency }}</h2>
<p class="muted">The report uses posted journals only, converted using each journal's locked exchange rate. Amounts are not combined across source currencies without an approved rate.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Type</th><th>Debit · {{ $business->base_currency }}</th><th>Credit · {{ $business->base_currency }}</th></tr></thead><tbody>
@foreach($accounts as $account)
@php $net=$balances[$account->id]??\Brick\Math\BigDecimal::zero(); @endphp
<tr><td><strong>{{ $account->code }} · {{ $account->name }}</strong></td><td>{{ ucfirst($account->type) }}</td>
<td>{{ $net->isGreaterThan(0) ? (string)$net->toScale(2) : '—' }}</td>
<td>{{ $net->isLessThan(0) ? (string)$net->negated()->toScale(2) : '—' }}</td></tr>
@endforeach
</tbody><tfoot><tr><td colspan="2"><strong>Net debit and credit balances</strong></td><td><strong>{{ $totalDebit->toScale(2) }}</strong></td><td><strong>{{ $totalCredit->toScale(2) }}</strong></td></tr></tfoot></table></div>
<p class="form-hint">A balanced trial balance is not the same as a complete profit-and-loss statement. Inventory consumption, spoilage, payroll and certain overhead allocations still require their own journal workflows.</p>
</section>
@endif
@endsection