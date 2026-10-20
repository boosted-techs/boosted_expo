@extends('layouts.console')
@section('title','Financial Reports')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCE REPORTS</span><h1>Financial statements & account activity</h1><p>Review posted transactions by period. All amounts are stated in the company's {{ $business->base_currency }} reporting currency.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Posted journals</a><a class="secondary-button" href="{{ route('finance.inventory.index') }}">COGS and stock valuation</a></div>
<div class="notice"><span>●</span><div><strong>Management reports from available journal entries — not final statutory accounts</strong><p>Opening balances, inventory costing on all historical exports, payroll, asset depreciation, tax adjustments, period closing and other accruals may be missing. Reports cannot be treated as audited statements, tax returns or a confirmed cash position without a complete chart and reviewed accounting policies.</p></div></div>
<section class="section-card"><h2>Select reporting period</h2>
<form method="GET" action="{{ route('finance.reports.index') }}" class="form-columns">
<label class="field"><span>From</span><input type="date" name="from" value="{{ $from }}" required></label>
<label class="field"><span>To</span><input type="date" name="to" value="{{ $to }}" required max="{{ now()->format('Y-m-d') }}"></label>
<button class="secondary-button" style="align-self:end">Update report</button>
</form><p class="form-hint">{{ $result['journal_count'] }} posted journals fall within this selected period.</p></section>
<section class="section-card"><h2>Profit and loss · {{ $from }} to {{ $to }}</h2>
<p class="muted">Values below reflect recognised revenue and expenses from posted journals during the selected period. Export revenue and COGS may not reflect all past shipments until their source records have been valued.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Type</th><th>{{ $business->base_currency }} value</th></tr></thead><tbody>
@foreach($result['profit_lines'] as $line)
<tr><td>{{ $line['account']->code }} · {{ $line['account']->name }}</td>
<td>{{ ucfirst($line['account']->type) }}</td><td>{{ $line['value'] }}</td></tr>
@endforeach
</tbody><tfoot>
<tr><td colspan="2"><strong>Total recognised income</strong></td><td>{{ $result['income'] }}</td></tr>
<tr><td colspan="2"><strong>Total expenses and losses</strong></td><td>{{ $result['expense'] }}</td></tr>
<tr><td colspan="2"><strong>Net reported result, before unrecorded adjustments</strong></td><td><strong>{{ $result['net'] }}</strong></td></tr>
</tfoot></table></div></section>
<section class="section-card"><h2>Financial position overview · as at {{ $to }}</h2>
<p class="muted">Cumulative balances from recorded journals only. Retained result is calculated from historical recognised income less historical expenses, not from a formally closed retained-earnings ledger.</p>
<h3>Assets</h3>
@foreach($result['balance_categories']['asset'] as $line)
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total assets</strong><strong>{{ $business->base_currency }} {{ $result['assets'] }}</strong></div>
<h3>Liabilities</h3>
@foreach($result['balance_categories']['liability'] as $line)
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total liabilities</strong><strong>{{ $result['liabilities'] }}</strong></div>
<h3>Opening capital & recorded equity</h3>
@foreach($result['balance_categories']['equity'] as $line)
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total recorded equity</strong><strong>{{ $result['equity'] }}</strong></div>
<div class="record"><strong>Unclosed cumulative income less expenses</strong><strong>{{ $result['cumulative_profit'] }}</strong></div>
<div class="record"><strong>Accounting difference</strong><strong>{{ $result['balance_variance'] }}</strong></div>
<p class="form-hint">A zero difference checks journal arithmetic. It does not establish that asset and liability values are complete or correct.</p>
</section>
<section class="section-card"><h2>Trial balance check</h2>
<div class="record"><strong>Debit balances</strong><strong>{{ $result['trial_debit'] }} {{ $business->base_currency }}</strong></div>
<div class="record"><strong>Credit balances</strong><strong>{{ $result['trial_credit'] }} {{ $business->base_currency }}</strong></div>
<h3>Detailed account ledgers</h3>
@foreach($accounts as $account)
<div class="record"><div><strong>{{ $account->code }} · {{ $account->name }}</strong><small>{{ ucfirst($account->type) }}</small></div>
<a class="action-link" href="{{ route('finance.reports.ledger',['account'=>$account->id,'from'=>$from,'to'=>$to]) }}">View journal lines</a></div>
@endforeach
</section>
@endsection