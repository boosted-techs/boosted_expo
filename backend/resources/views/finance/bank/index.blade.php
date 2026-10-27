@extends('layouts.console')
@section('title','Bank Reconciliation')
@section('content')
<div class="page-intro"><div><span class="eyebrow">BANK REVIEW</span><h1>Bank reconciliation</h1><p>Import statement lines and match them independently against previously posted payments and expenses.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('finance.index') }}" class="secondary-button">Finance dashboard</a><a href="{{ route('finance.documents') }}" class="secondary-button">Payment records</a></div>
<div class="notice"><span>●</span><div><strong>Uploaded CSV evidence is unauthenticated unless accompanied by a verified, trusted bank signature</strong><p>The system does not connect to your bank. You supply the statement, an independent reviewer checks matches, and no additional money or journals are created by reconciliation.</p></div></div>
@if($canImport)
<section class="section-card form-narrow"><h2>Import a bank statement CSV</h2>
<p class="muted">Up to 2,000 rows, 1 MB maximum. The exact columns are <code>date,direction,amount,reference,description</code>. Enter dates in YYYY-MM-DD format, direction <code>credit</code> or <code>debit</code>, and positive amounts.</p>
<form method="POST" action="{{ route('finance.bank.import') }}" enctype="multipart/form-data" class="form-stack">@csrf
@if($bankProfiles->isNotEmpty())
<label class="field"><span>Bank account from your chart</span><select name="bank_profile_id" required>
<option value="">Choose account to reconcile</option>
<option value="legacy">Legacy shared bank ledger (historical entries only)</option>
@foreach($bankProfiles as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->currency }}</option>@endforeach
</select></label>
<p class="form-hint">The currency below must match the chosen bank ledger exactly. Legacy bank entries remain separate.</p>
@else
<p class="form-hint">No named bank account has been configured. Importing will use the previous shared bank ledger. <a href="{{ route('finance.controls') }}">Configure banks</a></p>
@endif
<div class="form-columns"><label class="field"><span>Statement currency</span><x-currency-select :value="old('currency', $business->base_currency)" :historical="true" /></label>
<label class="field"><span>Start date</span><input name="period_from" type="date" required value="{{ old('period_from',now()->startOfMonth()->format('Y-m-d')) }}"></label>
<label class="field"><span>End date</span><input name="period_to" type="date" required max="{{ now()->format('Y-m-d') }}" value="{{ old('period_to',now()->format('Y-m-d')) }}"></label></div>
<div class="form-columns"><label class="field"><span>Opening balance</span><input name="opening_balance" type="number" step=".01" required value="{{ old('opening_balance','0.00') }}"></label>
<label class="field"><span>Closing balance</span><input name="closing_balance" type="number" step=".01" required value="{{ old('closing_balance','0.00') }}"></label></div>
<label class="field"><span>Bank statement CSV</span><input name="file" type="file" accept=".csv,.txt,text/csv" required></label>
<button class="primary-button">Import for review</button></form>
<p class="form-hint">Opening balance plus credits minus debits must equal closing balance. Identical file imports are rejected.</p></section>
@endif
<section class="section-card"><h2>Statements</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Statement</th><th>Currency</th><th>Period</th><th>Opening</th><th>Credits</th><th>Debits</th><th>Closing</th><th>Lines</th><th>Status</th></tr></thead><tbody>
@forelse($statements as $s)
<tr><td><a class="action-link" href="{{ route('finance.bank.show',$s->id) }}">{{ $s->reference }}</a><small>{{ $s->filename }}</small></td>
<td>{{ $s->currency }}</td><td>{{ $s->period_from->format('d M Y') }} to {{ $s->period_to->format('d M Y') }}</td>
<td>{{ $s->opening_balance }}</td><td>{{ $s->credits }}</td><td>{{ $s->debits }}</td><td>{{ $s->closing_balance }}</td><td>{{ $s->lines_count }}</td><td><span class="tiny-pill">{{ ucfirst($s->status) }}</span></td></tr>
@empty<tr><td colspan="9" class="empty-msg">No statements imported yet.</td></tr>@endforelse
</tbody></table></div>{{ $statements->links() }}</section>
@endsection