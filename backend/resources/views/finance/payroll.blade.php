@extends('layouts.console')
@section('title','Staff Payroll')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STAFF & FINANCE</span><h1>Payroll accruals</h1><p>Prepare company payroll from active staff memberships, then seek independent finance approval before recognising payroll costs.</p></div><span class="tiny-pill">{{ $business->base_currency }}</span></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('finance.index') }}" class="secondary-button">Finance dashboard</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.reviews.view'))
<a href="{{ route('finance.reviews') }}" class="secondary-button">Posting approvals</a>
@endif</div>
<div class="notice"><span>●</span><div><strong>Payroll is an accrual — it does not send salaries</strong><p>Enter gross earnings and deductions using verified staff records. This first release does not calculate shift wages, Uganda PAYE/NSSF or statutory contributions, and does not send funds. The approved posting records payroll expense, net wages payable and deduction liabilities only.</p></div></div>
@if($canPrepare)
<section class="section-card"><h2>Prepare a payroll run</h2>
<p class="muted">Enter amounts only for staff included in this run. Deductions are manual and must not exceed gross earnings. No overlapping run periods are allowed.</p>
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