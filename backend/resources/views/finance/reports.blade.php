@extends('layouts.console')
@section('title','Financial Reports')
@section('content')
<div class="page-intro"><div><span class="eyebrow">FINANCE REPORTS</span><h1>Financial statements & account activity</h1><p>Review posted transactions by period. Account totals use {{ $business->base_currency }}. Debt and loan details also show the original currency.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a><a class="secondary-button" href="{{ route('finance.reviews') }}">Posted journals</a><a class="secondary-button" href="{{ route('finance.inventory.index') }}">COGS and stock valuation</a></div>
<div class="form-actions no-print"><a class="secondary-button" href="{{ route('finance.cutover.index') }}">Opening data and source balances</a><a class="secondary-button" href="{{ route('finance.reports.activity',['from'=>$from,'to'=>$to]) }}">Spending and money movements</a><a class="secondary-button" href="{{ route('finance.reports.export',['from'=>$from,'to'=>$to]) }}">Export financial position</a><button type="button" class="secondary-button" onclick="window.print()">Print report</button></div>
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
@continue(\Brick\Math\BigDecimal::of($line['value'])->isZero())
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
@continue(\Brick\Math\BigDecimal::of($line['value'])->isZero())
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total assets</strong><strong>{{ $business->base_currency }} {{ $result['assets'] }}</strong></div>
<h3>Liabilities</h3>
@foreach($result['balance_categories']['liability'] as $line)
@continue(\Brick\Math\BigDecimal::of($line['value'])->isZero())
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total liabilities</strong><strong>{{ $result['liabilities'] }}</strong></div>
<h3>Opening capital & recorded equity</h3>
@foreach($result['balance_categories']['equity'] as $line)
@continue(\Brick\Math\BigDecimal::of($line['value'])->isZero())
<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>
@endforeach
<div class="record"><strong>Total recorded equity</strong><strong>{{ $result['equity'] }}</strong></div>
<div class="record"><strong>Unclosed cumulative income less expenses</strong><strong>{{ $result['cumulative_profit'] }}</strong></div>
<div class="record"><strong>Accounting difference</strong><strong>{{ $result['balance_variance'] }}</strong></div>
<p class="form-hint">A zero difference checks journal arithmetic. It does not establish that asset and liability values are complete or correct.</p>
</section>
<section class="section-card"><h2>Current and longer-term position · {{ $to }}</h2><p>Current means due within twelve months, through {{ $maturity['horizon'] }}. Missing dates and unmatched source amounts remain visible for review. Figures reconcile to the financial position above.</p>@foreach($maturity['categories'] as $category=>$lines)<h3>{{ ucfirst(str_replace('_',' ',$category)) }}</h3>@foreach($lines as $line)<div class="record"><span>{{ $line['account']->code }} · {{ $line['account']->name }}</span><strong>{{ $line['value'] }}</strong></div>@endforeach<div class="record"><strong>Total</strong><strong>{{ $business->base_currency }} {{ $maturity['totals'][$category] }}</strong></div>@endforeach</section>
<section class="section-card"><h2>Customers and suppliers · debt aging</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Document</th><th>Due</th><th>Age</th><th>Original currency outstanding</th><th>{{ $business->base_currency }} carrying value</th></tr></thead><tbody>@forelse($maturity['tradeAging'] as $row)<tr><td>{{ $row['reference'] }} · {{ str_replace('_',' ',$row['kind']) }}</td><td>{{ $row['due_on']??'Date needed' }}</td><td>{{ str_replace('_',' ',$row['bucket']) }}</td><td>{{ $row['currency'] }} {{ $row['amount'] }}</td><td>{{ $row['base'] }}</td></tr>@empty<tr><td colspan="5">No recorded trade debt as at this date.</td></tr>@endforelse</tbody></table></div></section>
<section class="section-card"><h2>Loans, borrowing and maturity</h2>@forelse($maturity['rows'] as $row)<p>{{ $row['reference'] }} · {{ $row['party'] }} · principal {{ $row['currency'] }} {{ $row['principal'] }} · carrying {{ $business->base_currency }} {{ $row['base'] }} · current principal {{ $row['current_base'] }} · matures {{ $row['matures_on']??'Date needed' }} · accrued interest {{ $row['interest'] }} · fees {{ $row['fees'] }}.</p>@empty<p>No recorded loan balances as at this date.</p>@endforelse</section>
<section class="section-card"><h2>Stock and material value reconciliation · {{ $to }}</h2>@foreach($reconciliation['inventory']['controls'] as $control)<p>{{ $control['label'] }} · source {{ $control['source'] }} · ledger {{ $control['book'] }} · difference {{ $control['difference'] }} {{ $business->base_currency }}.</p>@endforeach<details data-print-details><summary>Batch quantities and values</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Batch / product</th><th>Valued units</th><th>Observed units including packed goods</th><th>Difference</th><th>{{ $business->base_currency }} remaining value</th></tr></thead><tbody>@foreach($reconciliation['inventory']['rows'] as $row)<tr><td>{{ $row['reference'] }} · {{ $row['product'] }}</td><td>{{ $row['quantity'] }} {{ $row['unit'] }}</td><td>{{ $row['physical'] }} {{ $row['unit'] }}</td><td>{{ $row['quantity_difference'] }}</td><td>{{ $row['cost'] }}</td></tr>@endforeach</tbody></table></div></details></section>
<section class="section-card"><h2>Completeness and reconciliation</h2><p>{{ $reconciliation['checked'] }} journals checked against frozen posting evidence. {{ count($reconciliation['issues']) }} items need review. Checks cover recorded sources; the owner must also confirm that all business events have been entered.</p>@forelse($reconciliation['issues'] as $issue)<p><strong>{{ $issue['reference'] }}</strong> · {{ $issue['note'] }}</p>@empty<p>No missing source or posting evidence was found in these checks.</p>@endforelse<p><a href="{{ route('finance.controls') }}">Review accounting controls and period closing</a></p></section>
<section class="section-card"><h2>Trial balance check</h2>
<div class="record"><strong>Debit balances</strong><strong>{{ $result['trial_debit'] }} {{ $business->base_currency }}</strong></div>
<div class="record"><strong>Credit balances</strong><strong>{{ $result['trial_credit'] }} {{ $business->base_currency }}</strong></div>
<details><summary>Detailed account ledgers, including accounts with no balance</summary>
@foreach($accounts as $account)
<div class="record"><div><strong>{{ $account->code }} · {{ $account->name }}</strong><small>{{ ucfirst($account->type) }}</small></div>
<a class="action-link" href="{{ route('finance.reports.ledger',['account'=>$account->id,'from'=>$from,'to'=>$to]) }}">View journal lines</a></div>
@endforeach
</details></section>
@endsection