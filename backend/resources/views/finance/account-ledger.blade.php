@extends('layouts.console')
@section('title','Account Ledger')
@section('content')
<div class="page-intro"><div><span class="eyebrow">POSTED ACCOUNT ACTIVITY</span><h1>{{ $account->code }} · {{ $account->name }}</h1><p>{{ $from }} to {{ $to }} · Reporting currency {{ $business->base_currency }}</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.reports.index',['from'=>$from,'to'=>$to]) }}">All financial reports</a><a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a></div>
<div class="form-actions no-print"><a class="secondary-button" href="{{ route('finance.reports.ledger',['account'=>$account->id,'from'=>$from,'to'=>$to,'export'=>'csv']) }}">Export complete ledger</a><button class="secondary-button" type="button" onclick="window.print()">Print ledger</button></div><section class="section-card"><h2>Journal activity</h2>
<div class="record"><strong>Opening ledger balance (debit positive)</strong><strong>{{ $result['opening'] }} {{ $business->base_currency }}</strong></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Posting date</th><th>Journal</th><th>Description</th><th>Original currency debit / credit</th><th>Base debit</th><th>Base credit</th><th>Running balance</th></tr></thead><tbody>
@forelse($result['entries'] as $entry)
<tr><td>{{ $entry['date'] }}</td>
<td><a class="action-link" href="{{ route('finance.journal',$entry['journal_id']) }}">{{ $entry['reference'] }}</a></td>
<td>{{ $entry['description'] }}</td><td>{{ $entry['currency'] }} {{ $entry['original_debit'] }} / {{ $entry['original_credit'] }}</td><td>{{ $entry['debit'] }}</td><td>{{ $entry['credit'] }}</td><td>{{ $entry['balance'] }}</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No posted transactions on this account for the selected dates.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="6"><strong>Closing ledger balance (debit positive)</strong></td><td><strong>{{ $result['closing'] }}</strong></td></tr></tfoot></table></div>
<p class="form-hint">Credit balance accounts normally have negative debit-positive balances. This ledger does not include unposted bills, quotes, pending payments, or bank statement lines.</p>
</section>
@endsection