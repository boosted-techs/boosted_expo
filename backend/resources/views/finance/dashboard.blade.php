@extends('layouts.console')
@section('title','Money Overview')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCIAL MANAGEMENT</span><h1>Money overview</h1><p>Money held, amounts owed and work awaiting review.</p></div><span class="tiny-pill">{{ $business->base_currency }} BASE</span></div>

@php
$access=app(\App\Support\BusinessPermissions::class);$member=request()->attributes->get('membership');
$formatMoney=fn($value)=>preg_replace('/\B(?=(\d{3})+(?!\d))/',',',explode('.',(string)$value)[0]).'.'.(explode('.',(string)$value)[1]??'00');
@endphp
<div class="stats money-position-stats"><article class="stat"><span>Cash & bank</span><strong>{{ $formatMoney($overviewTotals['money']->toScale(2)) }}</strong><small>{{ $business->base_currency }} · posted balance</small></article><article class="stat"><span>Customers owe</span><strong>{{ $formatMoney($overviewTotals['receivable']->toScale(2)) }}</strong><small>{{ $business->base_currency }} · posted invoices</small></article><article class="stat"><span>Suppliers owed</span><strong>{{ $formatMoney($overviewTotals['payable']->negated()->toScale(2)) }}</strong><small>{{ $business->base_currency }} · posted bills</small></article><article class="stat"><span>Waiting for review</span><strong>{{ $pending }}</strong><small>Pending or approved to post</small></article></div>
<nav class="activity-cards" aria-label="Money tasks">
@foreach([
 ['Record money','Expenses, deposits, advances and transfers','finance.money.index','finance.documents.view'],
 ['Bills & payments','Customer receipts and supplier bills','finance.documents','finance.documents.view'],
 ['Review transactions','Approve and post prepared records','finance.reviews','finance.reviews.view'],
 ['Cash & transfers','Cash counts, owner funds and recurring bills','finance.treasury.index','finance.view'],
 ['Business position','Balances, commitments and cash movement','finance.position','finance.reports.view'],
 ['Reports & accounts','Profit, financial statements and ledger','finance.reports.index','finance.reports.view'],
 ['Buying & advances','Collections, requests and market buying','finance.trade.index','finance.view'],
 ['Accounting tools','Banks, opening balances and month-end','finance.controls','finance.controls.view']
] as [$label,$description,$target,$permission])@if($access->allows($member,$permission))<a class="activity-card money-task-card" href="{{ route($target) }}"><div><strong>{{ $label }}</strong><small>{{ $description }}</small></div><span aria-hidden="true">↗</span></a>@endif @endforeach
</nav>
@if($accounts->isEmpty())
<section class="section-card form-narrow"><h2>Activate financial accounts</h2>
<p class="muted">Activate your company’s accounts before posting transactions.</p>
@if($canSetup)<form method="POST" action="{{ route('finance.setup') }}">@csrf<button class="primary-button">Activate accounts</button></form>
@else<p class="form-hint">Ask your business owner or administrator to activate the financial chart.</p>@endif
</section>
@else
@if(count($missingAccounts)>0 && $canSetup)
<section class="section-card"><h2>Update accounting categories</h2>
<p class="muted">Add {{ count($missingAccounts) }} categories to use all money tools.</p>
<form method="POST" action="{{ route('finance.setup') }}">@csrf
<button class="secondary-button">Add categories</button></form></section>
@endif
<details class="section-card finance-status"><summary>Items waiting to be posted</summary><div class="stats">
<article class="stat"><span>Draft/unposted commercial invoices</span><strong>{{ $unposted['invoices'] }}</strong><small>Awaiting posting</small></article>
<article class="stat"><span>Unposted supplier bills</span><strong>{{ $unposted['supplier_bills'] }}</strong><small>Inventory and payable pending</small></article>
<article class="stat"><span>Pending payments & shipment costs</span><strong>{{ $unposted['payments']+$unposted['shipment_costs'] }}</strong><small>Awaiting posting</small></article>
<article class="stat"><span>Open finance reviews</span><strong>{{ $pending }}</strong><small>Pending or approved for posting</small></article>
</div>
</details>
<section class="section-card"><h2>Trial balance · {{ $business->base_currency }}</h2>
<p class="muted">Posted balances use each transaction’s approved exchange rate.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Type</th><th>Debit · {{ $business->base_currency }}</th><th>Credit · {{ $business->base_currency }}</th></tr></thead><tbody>
@foreach($accounts as $account)
@php $net=$balances[$account->id]??\Brick\Math\BigDecimal::zero(); @endphp
<tr><td><strong>{{ $account->code }} · {{ $account->name }}</strong></td><td>{{ ucfirst($account->type) }}</td>
<td>{{ $net->isGreaterThan(0) ? (string)$net->toScale(2) : '—' }}</td>
<td>{{ $net->isLessThan(0) ? (string)$net->negated()->toScale(2) : '—' }}</td></tr>
@endforeach
</tbody><tfoot><tr><td colspan="2"><strong>Net debit and credit balances</strong></td><td><strong>{{ $totalDebit->toScale(2) }}</strong></td><td><strong>{{ $totalCredit->toScale(2) }}</strong></td></tr></tfoot></table></div>
<p class="form-hint">Balances include posted records only. Check that stock costs, opening balances and expenses are complete before reviewing profit.</p>
</section>
@endif
@endsection