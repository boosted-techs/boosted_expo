@extends('layouts.console')
@section('title','Posted Journal')
@section('content')
<div class="page-intro"><div><span class="eyebrow">IMMUTABLE LEDGER</span><h1>{{ $journal->reference }}</h1><p>{{ ucfirst(str_replace('_',' ',$journal->source_type)) }} · {{ $journal->posted_on->format('d M Y') }} · {{ $journal->description }}</p></div><span class="tiny-pill">POSTED</span></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('finance.index') }}" class="secondary-button">Trial balance</a><a href="{{ route('finance.reviews') }}" class="secondary-button">Posting history</a></div>
<section class="section-card"><h2>Posting details</h2>
<div class="record"><strong>Document currency</strong><span>{{ $journal->currency }}</span></div>
<div class="record"><strong>Company base currency</strong><span>{{ $journal->base_currency }}</span></div>
<div class="record"><strong>Locked rate (1 {{ $journal->currency }} to {{ $journal->base_currency }})</strong><span>{{ $journal->rate_to_base }}</span></div>
<div class="record"><strong>Source</strong><span>{{ $journal->source_type }} / {{ $journal->source_id }}</span></div>
</section>
<section class="section-card"><h2>Debits and credits</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Debit ({{ $journal->currency }})</th><th>Credit ({{ $journal->currency }})</th><th>Base debit ({{ $journal->base_currency }})</th><th>Base credit ({{ $journal->base_currency }})</th><th>Notes</th></tr></thead><tbody>
@php $debit=\Brick\Math\BigDecimal::zero();$credit=\Brick\Math\BigDecimal::zero();$baseDebit=\Brick\Math\BigDecimal::zero();$baseCredit=\Brick\Math\BigDecimal::zero(); @endphp
@foreach($journal->lines as $line)
@php $debit=$debit->plus($line->debit);$credit=$credit->plus($line->credit);$baseDebit=$baseDebit->plus($line->base_debit);$baseCredit=$baseCredit->plus($line->base_credit); @endphp
<tr><td>{{ $line->account->code }} · {{ $line->account->name }}</td><td>{{ $line->debit }}</td><td>{{ $line->credit }}</td><td>{{ $line->base_debit }}</td><td>{{ $line->base_credit }}</td><td>{{ $line->memo }}</td></tr>
@endforeach
</tbody><tfoot><tr><td><strong>Total</strong></td><td>{{ $debit->toScale(2) }}</td><td>{{ $credit->toScale(2) }}</td><td>{{ $baseDebit->toScale(2) }}</td><td>{{ $baseCredit->toScale(2) }}</td><td></td></tr></tfoot></table></div>
<p class="form-hint">This entry cannot be edited or deleted from the application. Corrections and reversals will require a controlled future workflow.</p>
</section>
@endsection