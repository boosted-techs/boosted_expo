@extends('layouts.console')
@section('title','Staff Payroll')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAFF & FINANCE</span><h1>Payroll accruals</h1><p>Prepare payroll, review earnings and deductions, then request approval.</p></div><span class="tiny-pill">{{ $business->base_currency }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('finance.index') }}" class="secondary-button">Finance dashboard</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.reviews.view'))
<a href="{{ route('finance.reviews') }}" class="secondary-button">Posting approvals</a>
@endif</div>
<div class="notice"><span>●</span><div><strong>Payroll is an accrual — it does not send salaries</strong><p>Enter gross earnings and deductions using verified staff records. Approved time can calculate agreed hourly and daily earnings, including casual staff without logins. The approved-input task calculates a dated, reviewed PAYE/NSSF policy. Earlier manual preparation keeps its recorded basis. The approved posting records payroll expense, net wages payable and deduction liabilities only.</p></div></div>
@include('finance.payroll-inputs')
@if($canPrepare)
<section class="section-card"><details><summary>Earlier daily/hourly payroll with manually verified deductions</summary><h2>Prepare from approved attendance</h2><p>Daily rates pay once per Uganda start date. Hourly rates subtract recorded breaks. Resolve open and unapproved attendance first. Monthly, overtime and piece-rate arrangements need a separately verified earnings calculation.</p>
<form method="GET" class="form-actions"><input name="person_search" value="{{ request('person_search') }}" aria-label="Find a staff person" placeholder="Find staff"><button class="secondary-button">Find staff</button></form>{{ $people->links() }}
<form method="POST" action="{{ route('finance.payroll.from-time') }}" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>From</span><input name="period_from" type="date" required></label><label class="field"><span>Through</span><input name="period_to" type="date" max="{{ now()->toDateString() }}" required></label></div>
@foreach($people as $person)<label class="field"><span><input name="staff_person_ids[]" type="checkbox" value="{{ $person->id }}"> {{ $person->name }} · {{ $person->staff_code }}</span><input name="deductions[{{ $person->id }}]" type="number" step=".01" min="0" placeholder="Manual deduction, if any" aria-label="Deduction for {{ $person->name }}"></label>@endforeach
<label class="field"><span>Payroll evidence and adjustment explanation</span><textarea name="notes" required minlength="15" maxlength="2000"></textarea></label><button class="primary-button">Prepare from approved time</button></form></details></section>
<section class="section-card"><details><summary>Agreed daily / hourly, monthly and piece pay rates</summary><form method="POST" action="{{ route('finance.pay-rates.propose') }}" class="form-stack">@csrf
<label class="field"><span>Staff person</span><select name="staff_person_id" required>@foreach($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</select></label><label class="field"><span>Pay basis</span><select name="basis"><option value="daily">Per approved work day</option><option value="hourly">Per approved hour</option><option value="monthly">Monthly agreement</option><option value="piece">Per approved output unit</option></select></label>
<details><summary>Overtime, output and agreement changes</summary><label class="field"><span>Regular daily hours</span><input name="regular_daily_hours" type="number" step=".01" min=".01" max="24" value="8"></label><label class="field"><span>Agreed overtime multiplier (blank means no automatic overtime)</span><input name="overtime_multiplier" type="number" min="1" max="5" step=".001"></label><label class="field"><span>Monthly overtime hourly equivalent, when agreed</span><input name="overtime_hourly_amount" type="number" min=".01" step=".01"></label><label class="field"><span>Monthly unpaid salary-day divisor (blank uses calendar days)</span><input name="unpaid_day_divisor" type="number" min=".01" max="31" step=".01"></label><label class="field"><span>Piece-work unit</span><input name="output_unit" maxlength="30"></label><label class="field"><span>Piece-work type / agreed activity</span><input name="work_code" maxlength="50"></label><label class="field"><span>Replace an approved agreement from this new effective start</span><select name="supersedes_rate_id"><option value="">New agreement with no overlap</option>@foreach($rates->where('status','approved')->whereNull('superseded_on') as $prior)<option value="{{ $prior->id }}">{{ $prior->person?->name }} · {{ $prior->basis }} · {{ $prior->amount }} · {{ $prior->starts_on->format('d M Y') }}</option>@endforeach</select></label></details><label class="field"><span>Rate in {{ $business->base_currency }}</span><input name="amount" type="number" step=".01" min=".01" required></label><div class="form-columns"><label class="field"><span>Effective from</span><input name="starts_on" type="date" required></label><label class="field"><span>Effective through</span><input name="ends_on" type="date" required></label></div><label class="field"><span>Employment / pay agreement reference</span><input name="agreement_reference" required minlength="10" maxlength="250"></label><button class="secondary-button">Propose pay agreement</button></form></details></section>
@endif
<section class="section-card"><h2>Pay agreements</h2>@forelse($rates as $rate)<p>{{ $rate->person->name }} · {{ $rate->currency }} {{ $rate->amount }} {{ $rate->basis }} · {{ $rate->starts_on->format('d M Y') }}–{{ $rate->ends_on->format('d M Y') }} · {{ $rate->status }} · {{ $rate->agreement_reference }}</p>@if($canApprove && $rate->status==='proposed' && $rate->created_by!==auth()->id())<form method="POST" action="{{ route('finance.pay-rates.approve',$rate->id) }}">@csrf<button class="secondary-button">Approve agreement</button></form>@endif
@empty<p>No agreed daily / hourly rates recorded.</p>@endforelse{{ $rates->links() }}</section>
@if($canPrepare)
<section class="section-card"><h2>Prepare a payroll run</h2>
<p class="muted">Enter earnings and deductions for this run. Deductions cannot exceed gross pay; payroll periods cannot overlap.</p>
<form method="POST" action="{{ route('finance.payroll.prepare') }}" class="form-stack">@csrf
<div class="form-columns">
<label class="field"><span>From</span><input type="date" name="period_from" required value="{{ now()->startOfMonth()->format('Y-m-d') }}"></label>
<label class="field"><span>Through</span><input type="date" name="period_to" max="{{ now()->format('Y-m-d') }}" required value="{{ now()->format('Y-m-d') }}"></label>
</div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Team member</th><th>Role</th><th>Gross ({{ $business->base_currency }})</th><th>Manual deductions ({{ $business->base_currency }})</th></tr></thead><tbody>
@foreach($members as $member)
<tr><td><strong>{{ $member->user?->name }}</strong><small>{{ $member->user?->email }}</small></td><td>{{ ucfirst($member->role) }}</td>
<td><input type="number" step=".01" min=".01" name="entries[{{ $member->user_id }}][gross]" placeholder="Not in this run"></td>
<td><input type="number" step=".01" min="0" name="entries[{{ $member->user_id }}][deductions]" placeholder="0.00"></td>
</tr>@endforeach</tbody></table></div>
<label class="field"><span>Supporting documentation and payroll basis</span><textarea rows="2" name="notes" required minlength="15" maxlength="2000" placeholder="Approved employment terms, period, manual deduction evidence"></textarea></label>
<button class="primary-button">Prepare payroll for independent approval</button>
</form></section>
@endif
<section class="section-card"><h2>Payroll register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Run</th><th>Period</th><th>Gross</th><th>Deductions</th><th>Net payable</th><th>Status</th></tr></thead><tbody>
@forelse($runs as $run)
<tr><td><a class="action-link" href="{{ route('finance.payroll.show',$run->id) }}">{{ $run->reference }}</a></td>
<td>{{ $run->period_from->format('d M Y') }}–{{ $run->period_to->format('d M Y') }} · Revision {{ $run->revision }}</td>
<td>{{ $run->currency }} {{ $run->gross_total }}</td><td>{{ $run->deduction_total }}</td><td>{{ $run->net_total }}</td>
<td>{{ $run->posting_status==='posted'?'Accrued and posted':($run->posting_status==='reversed'?'Reversed by approved correction':'Unposted') }}</td></tr>
@empty<tr><td colspan="6" class="empty-msg">No payroll runs have been prepared yet.</td></tr>@endforelse
</tbody></table></div>{{ $runs->links() }}</section>
@endsection