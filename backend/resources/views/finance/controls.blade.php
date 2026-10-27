@extends('layouts.console')
@section('title','Accounting Controls')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ACCOUNTING CONTROLS</span><h1>Opening balances, bank accounts & period closing</h1><p>Set up reliable opening figures, keep each business bank separate, and control historical adjustments and financial period locking.</p></div><span class="tiny-pill">{{ $business->base_currency }} BASE</span></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('finance.index') }}">Finance dashboard</a>
<a class="secondary-button" href="{{ route('finance.reviews') }}">Journal approvals</a>
<a class="secondary-button" href="{{ route('finance.bank.index') }}">Bank statements</a>
<a class="secondary-button" href="{{ route('finance.payroll.index') }}">Payroll</a></div>
<div class="notice"><span>●</span><div><strong>Controlled accounting, not automatic certification</strong>
<p>Opening balances and concessions are drafts until separately reviewed and posted. Closing a period prevents historical backdated journal entries. Banks are business-specific ledger accounts; entering a bank does not connect to its online services. Always reconcile your approved opening figures before posting.</p></div></div>
<section class="section-card"><h2>1. Named bank & electronic accounts</h2>
<p class="muted">Each new bank receives its own general-ledger account and currency. Existing records remain on the legacy generic bank account; no past amounts are silently transferred.</p>
@if($canBank)
<form method="POST" action="{{ route('finance.controls.banks.store') }}" class="form-stack form-narrow">@csrf
<div class="form-columns"><label class="field"><span>Account nickname</span><input name="label" required minlength="3" maxlength="100" placeholder="e.g. Export USD settlement"></label>
<label class="field"><span>Bank or provider</span><input name="institution" required minlength="3" maxlength="100" placeholder="e.g. Business current account provider"></label></div>
<div class="form-columns"><label class="field"><span>Account currency</span><x-currency-select :value="old('currency', $business->base_currency)" /></label>
<label class="field"><span>Masked reference (optional)</span><input name="masked_reference" maxlength="32" placeholder="****1234" autocomplete="off"></label></div>
<button class="secondary-button">Add independent bank ledger</button>
<label><input type="checkbox" name="use_legacy_bank" value="1"> Assign this identity to the existing historical bank ledger after verifying it contains only this account and currency.</label></form>
@endif
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Institution</th><th>Currency</th><th>Reference</th><th>Ledger</th><th>Status</th></tr></thead><tbody>
@forelse($banks as $bank)
<tr><td><strong>{{ $bank->label }}</strong></td><td>{{ $bank->institution }}</td><td>{{ $bank->currency }}</td><td>{{ $bank->masked_reference?:'Not recorded' }}</td>
<td>{{ $bank->account?->code }} · {{ $bank->account?->name }}</td><td>{{ $bank->active?'Active':'Inactive' }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No named bank accounts yet. Older transactions use the original generic bank ledger.</td></tr>@endforelse
</tbody></table></div>
@if($canBank && $banks->isNotEmpty())
<h3>Enable bank-signed statement verification</h3>
<p class="form-hint">Bank signature authentication requires a signing key independently verified and provisioned by Boosted's platform administrators. Match a bank-issued signed account identifier to this ledger; the full account reference is never stored, only a keyed, one-way identity commitment. This does not connect to internet banking or initiate payments.</p>
@foreach($banks as $bank)
<details class="finance-details"><summary><strong>{{ $bank->label }}</strong> · {{ $bank->institution }}
· {{ $bank->account_identifier_sha256?'Bank identity commitment saved':'Full bank identity not yet configured' }}</summary>
<form method="POST" action="{{ route('finance.controls.banks.identifier',$bank->id) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Actual bank account identifier (not saved in plaintext)</span>
<input name="account_identifier" autocomplete="off" minlength="6" maxlength="100" required type="password"
placeholder="Enter bank-issued full account identifier"></label>
<button class="secondary-button">Save hashed bank identity for signed statement matching</button>
</form></details>
@endforeach
@endif
</section>
<section class="section-card"><h2>Foreign-currency bank opening balances</h2>
<p class="muted">Record historical funds in their actual bank-account currency and lock the approved exchange rate used to translate them into the company's base currency. This adds a reviewed bank asset and offsetting opening equity — never a bank transfer. Only an account with no existing ledger activity can receive this one-time opening entry.</p>
@if($canForeign && $foreignBanks->isNotEmpty())
<form method="POST" action="{{ route('finance.controls.foreign-opening.store') }}" class="form-stack form-narrow">@csrf
<div class="form-columns">
<label class="field"><span>Foreign bank account</span><select name="bank_profile_id" required><option value="">Choose foreign bank</option>
@foreach($foreignBanks as $profile)
<option value="{{ $profile->id }}">{{ $profile->label }} · {{ $profile->currency }}</option>
@endforeach</select></label>
<label class="field"><span>Bank currency (match selected account)</span>
<x-currency-select :value="old('currency')" /></label></div>
<div class="form-columns">
<label class="field"><span>Original foreign balance</span>
<input type="number" name="foreign_amount" step=".01" min=".01" required></label>
<label class="field"><span>Opening date</span>
<input type="date" name="effective_on" required max="{{ now()->format('Y-m-d') }}"
value="{{ now()->startOfYear()->format('Y-m-d') }}"></label></div>
<label class="field"><span>Previously approved historical exchange rate</span>
<select name="exchange_rate_id" required><option value="">Choose rate effective on/before opening</option>
@foreach($foreignRates as $fx)
<option value="{{ $fx->id }}">{{ $fx->currency }} → {{ $business->base_currency }}: {{ $fx->rate_to_base }} · {{ $fx->effective_on->format('d M Y') }} · {{ $fx->source_note }}</option>
@endforeach</select></label>
<label class="field"><span>Bank statement or verified historical balance evidence</span>
<textarea name="bank_evidence" required minlength="30" maxlength="3000" rows="3"
placeholder="State the original bank statement, valuation date, exact currency balance and independent supporting documents."></textarea></label>
<p class="form-hint">An overdraft, a currency revaluation or an already active bank account cannot use this flow. Ensure this equity is not included again in the normal company opening balance. The opening date should precede the first bank statement period that you want to balance-verify.</p>
<button class="secondary-button">Prepare foreign bank opening for review</button></form>
@endif
<h3>Foreign opening register</h3>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Account</th><th>Foreign balance</th><th>Base valuation</th><th>Effective</th><th>Review status</th></tr></thead><tbody>
@forelse($foreignOpenings as $opening)
<tr><td>{{ $opening->reference }}</td><td>{{ $banks->firstWhere('id',$opening->bank_profile_id)?->label }}</td>
<td>{{ $opening->currency }} {{ $opening->foreign_amount }}</td>
<td>{{ $business->base_currency }} {{ $opening->base_amount }} at {{ $opening->rate_to_base }}</td>
<td>{{ $opening->effective_on->format('d M Y') }}</td>
<td>{{ $opening->posting_status==='posted'?'Posted':'Awaiting independent review' }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No historical foreign bank opening entries have been prepared.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="section-card"><h2>2. Company opening balances</h2>
@if($opening)
<div class="record"><div><strong>{{ $opening->reference }}</strong><small>Effective {{ $opening->effective_on->format('d M Y') }} · Total debits and credits {{ $business->base_currency }} {{ $opening->data_snapshot['debits'] }}</small></div>
<span class="tiny-pill">{{ $opening->posting_status==='posted'?'POSTED':'Awaiting approval' }}</span></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Debit {{ $business->base_currency }}</th><th>Credit {{ $business->base_currency }}</th></tr></thead><tbody>
@foreach($opening->data_snapshot['lines'] as $entry)
<tr><td>{{ $entry['account_label'] }}</td><td>{{ $entry['debit'] }}</td><td>{{ $entry['credit'] }}</td></tr>@endforeach
</tbody></table></div>
@else
<p class="muted">Enter beginning balances in the company's base currency only. Include owner capital or historical equity where required. Every entered row must have either a debit or a credit, and total debits must equal total credits exactly.</p>
@if($canOpen)
<form method="POST" action="{{ route('finance.controls.opening.store') }}" class="form-stack">@csrf
<label class="field form-narrow"><span>Opening balance effective date</span><input type="date" name="effective_on" required value="{{ old('effective_on',now()->startOfYear()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}"></label>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Opening debit ({{ $business->base_currency }})</th><th>Opening credit ({{ $business->base_currency }})</th></tr></thead><tbody>
@foreach(range(0,7) as $rowIndex)
<tr><td><select name="lines[{{ $rowIndex }}][account_id]" {{ $rowIndex<2?'required':'' }}><option value="">Choose account</option>
@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></td>
<td><input name="lines[{{ $rowIndex }}][debit]" type="number" step=".01" min="0" placeholder="0.00"></td>
<td><input name="lines[{{ $rowIndex }}][credit]" type="number" step=".01" min="0" placeholder="0.00"></td></tr>
@endforeach
</tbody></table></div>
<p class="form-hint">Use at least two rows. Leave unused account rows blank. No amount is posted until the journal has been independently approved.</p>
<button class="primary-button">Prepare opening balance review</button></form>
@endif
@endif
</section>
<section class="section-card"><h2>3. Accounting periods & month-end closure</h2>
<p class="muted">Create non-overlapping periods within a single month. Closing is blocked while eligible financial source documents, journal approvals or imported bank statements remain unresolved through that period end. A different authorised person must approve closure.</p>
@if($canPeriod)
<form method="POST" action="{{ route('finance.controls.periods.store') }}" class="form-columns">@csrf
<label class="field"><span>Period starts</span><input type="date" name="period_from" required value="{{ now()->startOfMonth()->format('Y-m-d') }}"></label>
<label class="field"><span>Period ends</span><input type="date" name="period_to" required max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></label>
<button class="secondary-button" style="align-self:end">Create period</button></form>
@endif
@forelse($periods as $period)
<div class="finance-details" style="margin:12px 0">
<div class="record"><div><strong>{{ $period->period_from->format('d M Y') }} – {{ $period->period_to->format('d M Y') }}</strong><small>{{ $period->reason?:'No closing reason entered yet' }}</small></div><span class="tiny-pill">{{ ucfirst($period->status) }}</span></div>
@if($period->status==='open' && $canPeriod)
<form method="POST" action="{{ route('finance.controls.periods.submit',$period->id) }}" class="form-stack">@csrf
<label class="field"><span>Close justification and reconciliation evidence</span><input name="reason" required minlength="20" maxlength="2000" placeholder="Reviewed journal totals, documents and bank reconciliations"></label>
<button class="secondary-button">Request independent month-end closure</button></form>
@elseif($period->status==='pending' && $canPeriodReview && $period->requested_by!==auth()->id())
<div class="form-columns">
<form method="POST" action="{{ route('finance.controls.periods.review',['period'=>$period->id,'action'=>'approve']) }}" class="form-stack">@csrf
<label class="field"><span>Independent close approval note</span><input name="reason" required minlength="10" maxlength="2000"></label><button class="primary-button">Approve and lock period</button></form>
<form method="POST" action="{{ route('finance.controls.periods.review',['period'=>$period->id,'action'=>'reject']) }}" class="form-stack">@csrf
<label class="field"><span>Why is closure rejected?</span><input name="reason" required minlength="10" maxlength="2000"></label><button class="secondary-button">Reject closing request</button></form></div>
@endif
@php $pendingReopen=$reopenRequests->first(fn($r)=>$r->period_id===$period->id && $r->status==='pending'); @endphp
@if($period->status==='closed' && $canReopen && !$pendingReopen)
<details class="finance-details"><summary>Request a formally audited reopening</summary>
<p class="form-hint">This does not reopen the period by itself. A different authorised owner must independently approve. Reopen periods in reverse chronological order, latest first. The previous closing snapshot will remain permanently recorded.</p>
<form method="POST" action="{{ route('finance.controls.periods.reopening.request',$period->id) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Specific earlier-period error or return requiring correction</span>
<textarea name="reason" minlength="30" maxlength="3000" rows="2" required></textarea></label>
<label class="field"><span>Materiality and financial reporting impact assessment</span>
<textarea name="materiality_assessment" minlength="30" maxlength="3000" rows="2" required></textarea></label>
<label class="field"><span>Bank statement, auditor reference or independently reviewed supporting evidence</span>
<textarea name="external_evidence" minlength="30" maxlength="3000" rows="2" required></textarea></label>
<button class="secondary-button">Request independent reopening review</button>
</form></details>
@endif
@if($pendingReopen)
<div class="record"><div><strong>Reopening requested · approval pending</strong>
<small>{{ $pendingReopen->reason }}</small></div><span class="tiny-pill">Original close retained</span></div>
@if($canReopenReview && $pendingReopen->requested_by!==auth()->id())
<div class="form-columns">
<form method="POST" action="{{ route('finance.controls.periods.reopening.review',['case'=>$pendingReopen->id,'decision'=>'approve']) }}" class="form-stack">@csrf
<label class="field"><span>Independent written approval and restatement requirements</span>
<input name="notes" required minlength="20" maxlength="3000" placeholder="Reviewed original close, correction and materiality evidence"></label>
<button class="primary-button">Approve documented reopening</button></form>
<form method="POST" action="{{ route('finance.controls.periods.reopening.review',['case'=>$pendingReopen->id,'decision'=>'reject']) }}" class="form-stack">@csrf
<label class="field"><span>Reason reopening is refused</span>
<input name="notes" required minlength="20" maxlength="3000" placeholder="Explain why supporting evidence is insufficient"></label>
<button class="secondary-button">Reject request; keep period closed</button></form>
</div>
@endif
@endif
</div>
@empty<p class="empty-msg">No accounting periods created yet.</p>@endforelse
<h3>Historical close and reopening audit</h3>
@forelse($reopenRequests as $history)
<div class="record"><div><strong>Period #{{ $history->period_id }} · {{ ucfirst($history->status) }}</strong>
<small>{{ $history->reason }} · Original close hash {{ substr($history->original_close_hash,0,14) }}…</small>
<small>{{ $history->materiality_assessment }}</small></div>
<span class="tiny-pill">{{ $history->reviewed_at?->format('d M Y H:i')?:'Awaiting independent review' }}</span></div>
@empty<p class="empty-msg">No prior-period reopening requests recorded.</p>@endforelse
<p class="form-hint">No financial period is reopened automatically. Reopening and closing are independently authorised and recorded. Previously issued statutory statements may require professional restatement, amended filings or auditor approval outside this software.
</section>
<section class="section-card"><h2>4. Customer & supplier balance adjustments</h2>
<p class="muted">A partial concession changes the amount still owed, and uses the original approved exchange rate. It cannot be used when payments already exist. Customer adjustments are limited to zero-tax invoices; supplier adjustments require produce that has not been packed or written off.</p>
@if($canAdjust)
<div class="split-panels"><div><h3>Customer price concession</h3>
@foreach($invoices as $invoice)<details class="finance-details"><summary>{{ $invoice->reference }} · {{ $invoice->currency }} {{ $invoice->grand_total }}</summary>
<form method="POST" action="{{ route('finance.controls.adjustments.store',['kind'=>'customer','id'=>$invoice->id]) }}" class="form-stack">@csrf
<label class="field"><span>Partial amount to credit</span><input name="amount" type="number" step=".01" min=".01" required></label>
<label class="field"><span>Detailed reason</span><textarea name="reason" required minlength="20" maxlength="3000" rows="2"></textarea></label>
<button class="secondary-button">Prepare buyer concession</button></form></details>@endforeach
@if($invoices->isEmpty())<p class="empty-msg">No eligible posted zero-tax invoices.</p>@endif</div>
<div><h3>Supplier purchase concession</h3>
@foreach($bills as $bill)<details class="finance-details"><summary>{{ $bill->reference }} · {{ $bill->currency }} {{ $bill->total }}</summary>
<form method="POST" action="{{ route('finance.controls.adjustments.store',['kind'=>'supplier','id'=>$bill->id]) }}" class="form-stack">@csrf
<label class="field"><span>Partial amount to reduce the payable</span><input name="amount" type="number" step=".01" min=".01" required></label>
<label class="field"><span>Supplier agreement / supporting reason</span><textarea name="reason" required minlength="20" maxlength="3000" rows="2"></textarea></label>
<button class="secondary-button">Prepare supplier concession</button></form></details>@endforeach
@if($bills->isEmpty())<p class="empty-msg">No posted supplier bills.</p>@endif</div></div>
@endif
<h3>Adjustment history</h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Document</th><th>Type</th><th>Original</th><th>Amount</th><th>Status</th><th>Reason</th></tr></thead><tbody>
@forelse($adjustments as $a)
<tr><td>{{ $a->reference }}</td><td>{{ ucfirst($a->kind) }}</td><td>{{ $a->data_snapshot['document_reference'] }}</td>
<td>{{ $a->currency }} {{ $a->amount }}</td><td>{{ $a->posting_status==='posted'?'Posted':'Unposted' }}</td>
<td>{{ $a->reason }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No concessions recorded yet.</td></tr>@endforelse
</tbody></table></div>
</section>
@endsection