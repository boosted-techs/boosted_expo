@extends('layouts.console')
@section('title','Payroll Details')
@section('content')
<div class="page-intro"><div><span class="eyebrow">RESTRICTED PAYROLL RECORD</span><h1>{{ $run->reference }}</h1><p>{{ $run->period_from->format('d M Y') }} to {{ $run->period_to->format('d M Y') }} · Revision {{ $run->revision }} · {{ $run->currency }}</p></div>
<span class="tiny-pill">{{ $run->posting_status==='posted'?'POSTED':($run->posting_status==='reversed'?'REVERSED':'UNPOSTED') }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('finance.payroll.index') }}">All payroll runs</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.reviews.view'))
<a class="secondary-button" href="{{ route('finance.reviews') }}">Journal approval queue</a>@endif</div>
<div class="notice"><span>●</span><div><strong>Accrued wages, not disbursed</strong><p>Posting payroll creates an expense and liabilities for net salary and manual deductions. This system has not verified statutory calculations or paid staff.</p></div></div>
<section class="section-card"><h2>Employees and amounts</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Team member</th><th>Gross</th><th>Manual deductions</th><th>Net payable</th></tr></thead><tbody>
@foreach($run->lines as $line)
<tr><td>@if($run->posting_status==='posted')<a href="{{ route('finance.payroll.payslip',[$run->id,$line->id]) }}" target="_blank" rel="noopener">Payslip</a> · @endif<strong>{{ ($line->name_snapshot??$line->user?->name) }}</strong></td><td>{{ $run->currency }} {{ $line->gross }}</td>
<td>{{ $line->deductions }}</td><td>{{ $line->net }}</td></tr>@endforeach
</tbody><tfoot><tr><td><strong>Total</strong></td><td><strong>{{ $run->gross_total }}</strong></td>
<td><strong>{{ $run->deduction_total }}</strong></td><td><strong>{{ $run->net_total }}</strong></td></tr></tfoot></table></div>
<h3>Preparation basis</h3><p>{{ $run->notes }}</p><p class="form-hint">Gross earnings come from the recorded preparation basis; deductions are manually verified. Each approved settlement requires external evidence and is a ledger record, not a banking instruction. Payslips preserve the approved amounts. Statutory computation and automatic bank disbursements require separately verified arrangements.</p>
</section>
@if($canSettle)
<section class="section-card">
<h2>Employee wage settlement register</h2>
<p class="muted">These are manually evidenced payments. This screen does not send money. Pending suggestions reserve the outstanding amount until approved or rejected by independent finance staff.</p>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Employee</th><th>Net payroll</th><th>Available for new settlement</th></tr></thead><tbody>
@foreach($run->lines as $line)
<tr><td>{{ ($line->name_snapshot??$line->user?->name) }}</td><td>{{ $run->currency }} {{ $line->net }}</td><td>{{ $run->currency }} {{ $remaining[$line->id] }}</td></tr>
@endforeach
<tr><td><strong>Manual deductions / withholding</strong></td><td>{{ $run->currency }} {{ $run->deduction_total }}</td><td>{{ $run->currency }} {{ $withholdingRemaining }}</td></tr>
</tbody></table></div>
@if($run->posting_status==='posted' && (!$reversal || $reversal->posting_status==='withdrawn'))
<div class="split-panels">
<div><h3>Record evidence of a staff salary payment</h3>
<form method="POST" action="{{ route('finance.payroll.settlements.store',$run->id) }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="salary">
<input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label class="field"><span>Employee / outstanding net wages</span>
<select name="payroll_line_id" required><option value="">Select employee</option>
@foreach($run->lines as $line)
@if(\Brick\Math\BigDecimal::of($remaining[$line->id])->isGreaterThan(0))
<option value="{{ $line->id }}">{{ ($line->name_snapshot??$line->user?->name) }} · {{ $run->currency }} {{ $remaining[$line->id] }} available</option>
@endif
@endforeach
</select></label>
<label class="field"><span>Amount shown on actual payment evidence ({{ $run->currency }})</span>
<input type="number" name="amount" min=".01" step=".01" required></label>
<div class="form-columns">
<label class="field"><span>Payment method</span><select name="cash_account_key">
<option value="bank">Bank / electronic</option><option value="cash">Cash</option></select></label>
<label class="field"><span>Payment date</span><input type="date" name="paid_on"
min="{{ $run->period_to->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}"
value="{{ now()->format('Y-m-d') }}" required></label></div>
@if($banks->isNotEmpty())
<label class="field"><span>Named bank account (choose when using bank/electronic)</span>
<select name="bank_profile_id"><option value="">Cash only — no bank selected</option>
@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->currency }}</option>@endforeach
</select></label>
@endif
<label class="field"><span>Bank transfer or cash voucher reference</span>
<input name="external_reference" minlength="3" maxlength="150" required></label>
<label class="field"><span>Supporting payment evidence</span><textarea name="evidence_note"
required minlength="20" maxlength="2000" rows="3"
placeholder="Voucher, banking proof, employee confirmation and transaction date"></textarea></label>
<button class="primary-button">Record salary settlement for finance review</button>
</form></div>
<div><h3>Record remittance of manually entered deductions</h3>
<form method="POST" action="{{ route('finance.payroll.settlements.store',$run->id) }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="withholding">
<input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label class="field"><span>Receiving authority or organisation</span><input name="recipient_name"
maxlength="150" minlength="3" required placeholder="Enter actual deduction recipient"></label>
<label class="field"><span>Remitted amount ({{ $run->currency }}, available {{ $withholdingRemaining }})</span>
<input type="number" name="amount" min=".01" step=".01" required></label>
<div class="form-columns">
<label class="field"><span>Paid via</span><select name="cash_account_key">
<option value="bank">Bank / electronic</option><option value="cash">Cash</option></select></label>
<label class="field"><span>Payment date</span><input type="date" name="paid_on"
min="{{ $run->period_to->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}"
value="{{ now()->format('Y-m-d') }}" required></label></div>
@if($banks->isNotEmpty())
<label class="field"><span>Named bank account (when using bank/electronic)</span>
<select name="bank_profile_id"><option value="">Cash only — no bank selected</option>
@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} · {{ $bank->currency }}</option>@endforeach
</select></label>
@endif
<label class="field"><span>Payment transaction / voucher reference</span>
<input name="external_reference" minlength="3" maxlength="150" required></label>
<label class="field"><span>Supporting remittance evidence</span>
<textarea name="evidence_note" required minlength="20" maxlength="2000" rows="3"
placeholder="Receipt number, payee, remittance period and authorisation"></textarea></label>
<p class="form-hint">No PAYE/NSSF rules or contribution rates are calculated here. Only remittances to an identified recipient for existing manual deductions may be recorded.</p>
<button class="secondary-button">Record deduction remittance for finance review</button>
</form></div></div>
@endif
<h3>Recorded salary payments & deduction remittances</h3>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Purpose</th><th>Payee / employee</th><th>Amount</th><th>Date</th><th>Evidence reference</th><th>Review status</th></tr></thead><tbody>
@forelse($settlements as $s)
<tr><td>{{ $s->reference }}</td><td>{{ $s->kind==='salary'?'Employee salary':'Manual withholding' }}</td>
<td>{{ $s->kind==='salary' ? ($run->lines->firstWhere('id',$s->payroll_line_id)?->name_snapshot??$run->lines->firstWhere('id',$s->payroll_line_id)?->user?->name) : $s->recipient_name }}</td>
<td>{{ $s->currency }} {{ $s->amount }}</td><td>{{ $s->paid_on->format('d M Y') }}</td>
<td>{{ $s->external_reference }}</td><td>{{ ucfirst($s->status) }}</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No staff payments or remittances recorded.</td></tr>@endforelse
</tbody></table></div>
</section>
@endif
@if($canCorrect)
<section class="section-card"><h2>Correct an approved payroll accrual</h2>
<p class="muted">A correction creates a new balanced reversal journal; it never changes or erases the original payroll journal. It can only proceed while there are no pending or posted settlements for this run.</p>
@if($reversal)
<div class="record"><div><strong>{{ $reversal->reference }}</strong>
<small>{{ $reversal->reason }}</small></div>
<span class="tiny-pill">{{ match($reversal->posting_status){
    'posted'=>'Posted reversal','withdrawn'=>'Withdrawn',default=>'Awaiting independent review'
} }}</span></div>
@if($canWithdraw)
<form method="POST" action="{{ route('finance.payroll.correction.withdraw',$run->id) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Reason for withdrawing the unposted correction</span>
<input name="reason" required minlength="20" maxlength="2000" placeholder="State the verification and decision"></label>
<button class="secondary-button">Withdraw unposted correction</button></form>
@elseif($reversal->posting_status==='not_posted')
<p class="form-hint">Independent finance review must be rejected before this correction may be withdrawn.</p>
@endif
@if($canCorrect && $reversal->posting_status==='withdrawn' && $run->posting_status==='posted')
<form method="POST" action="{{ route('finance.payroll.correction.store',$run->id) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>New reason to reactivate the correction</span>
<textarea name="reason" required minlength="30" maxlength="3000" rows="2"></textarea></label>
<button class="secondary-button">Resume the correction approval workflow</button></form>
@endif
@if($reversal->posting_status==='posted')
<p class="form-hint">The original accrual has been reversed. A replacement payroll run for the same period is possible while that reporting period remains open. Corrections affecting locked periods require a later adjustment workflow.</p>
@endif
@if($canCorrect && $correctionEvents->isNotEmpty())
<h3>Correction audit history</h3>
@foreach($correctionEvents as $event)
<div class="record"><div><strong>{{ ucwords($event->action) }}</strong>
<small>{{ $event->reason }}</small></div><small>{{ $event->created_at->format('d M Y H:i') }}</small></div>
@endforeach
@endif
@elseif($canCorrect && $run->posting_status==='posted' &&
    !$settlements->whereIn('status',['pending','posted'])->count())
<form method="POST" action="{{ route('finance.payroll.correction.store',$run->id) }}" class="form-stack form-narrow">@csrf
<label class="field"><span>Detailed correction reason and supporting evidence</span>
<textarea name="reason" required minlength="30" maxlength="3000" rows="3"
placeholder="Describe the payroll error and the records supporting a full reversal"></textarea></label>
<button class="secondary-button">Prepare complete payroll reversal for review</button></form>
@elseif($run->posting_status==='reversed')
<p class="form-hint">This payroll has been reversed. Create a new payroll run for the exact original period to prepare its next revision, subject to fresh approval.</p>
@else<p class="form-hint">Correction is unavailable while payroll settlements exist or the original accrual has not been posted.</p>
@endif
</section>
@endif
@endsection