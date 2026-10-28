@extends('layouts.console')
@section('title','Shipment Details')
@section('content')
@if($shipment->sales_order_id)<a class="secondary-button" href="{{ route('sales-orders.show',[$shipment->sales_order_id,'tab'=>'delivery']) }}">Order delivery & transport handovers</a>@endif
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'labels.generate'))<div class="form-actions" style="margin-bottom:16px"><a class="secondary-button" href="{{ route('labels.print',['shipment'=>$shipment->id]) }}">Print shipment stickers</a></div>@endif
@php $business=request()->attributes->get('activeBusiness'); @endphp
<div class="page-intro"><div><span class="eyebrow">SHIPMENT RECORD</span><h1>{{ $shipment->reference }}</h1>
<p>{{ $shipment->customer->name }} · {{ $shipment->origin_country }} to {{ $shipment->destination_country }} · {{ ucfirst($shipment->status) }}</p></div>
<span class="tiny-pill">{{ strtoupper($shipment->status) }}</span></div>
<div class="form-actions" style="margin-bottom:20px">
<a class="secondary-button" href="{{ route('shipments.index') }}">All shipments</a>
@if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),'finance.view'))
<a class="secondary-button" href="{{ route('finance.contribution',$shipment->id) }}">Shipment contribution estimate</a>@endif
@if($canManage && $shipment->status==='draft')
<a class="secondary-button" href="{{ route('shipments.edit',$shipment->id) }}">Edit details</a>
@endif
@if($canViewDocs)<a class="secondary-button" href="{{ route('export-documents.index') }}">Document history</a>@endif
@if($canGenerate)<a class="secondary-button" href="{{ route('document-templates.index') }}">Document templates</a>@endif
</div>
@if(in_array($shipment->status,['dispatched','delivered'],true))
<div class="notice"><span>●</span><div><strong>Movement recorded manually</strong><p>The export team has marked this shipment {{ $shipment->status }}. This entry is not independently verified by an airline or customs office.</p></div></div>
@endif
<section class="section-card">
<h2>Linked customer order</h2>
@if($shipment->salesOrder)
<div class="record"><strong>Confirmed order</strong><a class="action-link" href="{{ route('sales-orders.show',$shipment->sales_order_id) }}">{{ $shipment->salesOrder->reference }}</a></div>
<div class="record"><strong>Confirmed value</strong><span>{{ $shipment->salesOrder->currency }} {{ $shipment->salesOrder->grand_total }}</span></div>
<p class="form-hint">Only this shipment's assigned cartons can be invoiced. Other order quantities remain available for additional shipments.</p>
@else
<p class="notice-message">Link a confirmed order to issue a commercial invoice.</p>
@if($canManage && $shipment->status==='draft')
<a href="{{ route('sales-orders.index') }}" class="action-link">Link via confirmed sales orders</a>
@endif
@endif
</section>
<section class="section-card">
<h2>Shipment and flight information</h2><p class="form-hint">Times shown in company timezone: {{ $business->timezone }}.</p>
<div class="shipment-facts">
<div><small>Buyer / consignee</small><strong>{{ $shipment->customer->name }}</strong><span>{{ $shipment->customer->city }} · {{ $shipment->customer->country_code }}</span></div>
<div><small>Route</small><strong>{{ $shipment->origin_airport ?: $shipment->origin_country }} → {{ $shipment->destination_airport ?: $shipment->destination_country }}</strong><span>{{ $shipment->origin_country }} → {{ $shipment->destination_country }}</span></div>
<div><small>Flight number</small><strong>{{ $shipment->flight_number ?: 'Not recorded' }}</strong><span>{{ $shipment->carrier ?: 'Carrier not recorded' }}</span></div>
<div><small>Air waybill (AWB)</small><strong>{{ $shipment->air_waybill ?: 'Pending' }}</strong><span>Reference supplied by your carrier</span></div>
<div><small>Scheduled departure</small><strong>{{ $shipment->scheduled_departure_at?->copy()->timezone($business->timezone)->format('d M Y H:i') ?: 'Not planned' }}</strong></div>
<div><small>Estimated arrival</small><strong>{{ $shipment->estimated_arrival_at?->copy()->timezone($business->timezone)->format('d M Y H:i') ?: 'Not planned' }}</strong></div>
<div><small>Recorded departure</small><strong>{{ $shipment->actual_departure_at?->copy()->timezone($business->timezone)->format('d M Y H:i') ?: 'Not recorded' }}</strong></div>
<div><small>Recorded arrival</small><strong>{{ $shipment->actual_arrival_at?->copy()->timezone($business->timezone)->format('d M Y H:i') ?: 'Not recorded' }}</strong></div>
</div>
@if($shipment->incoterm)<p class="form-hint">Agreed delivery term: {{ $shipment->incoterm }}</p>@endif
@if($shipment->notes)<p class="form-hint">Notes: {{ $shipment->notes }}</p>@endif
</section>
<section class="section-card"><h2>Allocated cartons ({{ $shipment->allocations->count() }})</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Carton reference</th><th>Product</th><th>Net</th><th>Gross</th><th>Grade</th><th>Source batches</th><th></th></tr></thead><tbody>
@forelse($shipment->allocations as $allocation)
@php $carton=$allocation->carton; @endphp
<tr><td><a class="action-link" href="{{ route('cartons.show',$carton->id) }}">{{ $carton->carton_code }}</a></td>
<td>{{ $carton->packingJob?->product?->name ?: '—' }}</td><td>{{ $carton->net_weight_kg }} kg</td><td>{{ $carton->gross_weight_kg }} kg</td>
<td>{{ $carton->grade ?: '—' }}</td><td>{{ $carton->contents->pluck('batch.batch_code')->filter()->implode(', ') }}</td>
<td>@if($canManage && $shipment->status==='draft')
<form method="POST" action="{{ route('shipments.release',['shipment'=>$shipment->id,'carton'=>$carton->id]) }}" data-confirm="Return this carton to unallocated packed stock?">@csrf @method('DELETE')
<button class="text-button">Release</button></form>
@else<span class="tiny-pill">{{ ucfirst($carton->status) }}</span>@endif</td></tr>
@empty<tr><td colspan="7" class="empty-msg">No cartons allocated. Complete a packing job, then select its cartons below.</td></tr>@endforelse
</tbody></table></div></section>
@if($canManage && $shipment->status==='draft')
<section class="section-card">
<h2>Allocate packed cartons</h2>
<p class="muted">Add cartons from completed packing jobs. Each carton can belong to one shipment.</p>
<form method="GET" action="{{ route('shipments.show',$shipment->id) }}" class="search-row">
<label class="field grow"><span>Find carton by reference</span><input name="carton_search" type="search" placeholder="Enter carton code" value="{{ request('carton_search') }}"></label><button class="secondary-button">Find</button></form>
<form method="POST" action="{{ route('shipments.allocate',$shipment->id) }}" class="form-stack">@csrf
<div class="shipment-picker">
@forelse($available as $carton)
<label class="check-row"><input type="checkbox" name="carton_ids[]" value="{{ $carton->id }}">
<div><strong>{{ $carton->carton_code }}</strong><small>{{ $carton->packingJob?->product?->name }} · {{ $carton->net_weight_kg }} kg net · {{ $carton->grade ?: 'Ungraded' }}</small></div></label>
@empty<p class="empty-msg">No unallocated completed cartons found. <a class="action-link" href="{{ route('packing.index') }}">Open Packing Jobs</a>.</p>@endforelse
</div>
@if($available->count()>=100)<p class="form-hint">Showing the first 100 matching cartons. Search by carton reference to find others. Allocate up to 50 cartons per submission.</p>@endif
@if($available->isNotEmpty())<button class="primary-button">Allocate selected cartons</button>@endif
</form>
</section>
@endif
@include('shipments._readiness')
<section class="section-card"><h2>Shipment control</h2>
@if($shipment->status==='draft' && $canManage)
<p class="muted">Check cartons, flight details and AWB, then mark ready.</p>
<form method="POST" action="{{ route('shipments.ready',$shipment->id) }}" class="form-actions">@csrf
<button class="primary-button">Mark shipment ready</button></form>
@elseif($shipment->status==='ready')
<div class="form-actions">
@if($canManage)
<form method="POST" action="{{ route('shipments.reopen',$shipment->id) }}">@csrf<button class="secondary-button">Reopen for editing</button></form>
@endif</div>
@if($approvalRequired)
<div class="notice"><span>●</span><div><strong>Independent dispatch sign-off required</strong>
<p>This business requires management approval for these exact flight and carton details before departure can be recorded.</p></div></div>
@if(!$dispatchReview && $canManage)
<form method="POST" action="{{ route('shipments.approval.submit',$shipment->id) }}" class="form-actions">@csrf
<button class="secondary-button">Request dispatch approval</button></form>
@elseif($dispatchReview)
<div class="record"><strong>Management review</strong><span>{{ ucfirst($dispatchReview->status) }}</span></div>
@if($dispatchReview->review_note)<p class="muted">{{ $dispatchReview->review_note }}</p>@endif
@if($dispatchReview->status==='pending' && $canReviewDispatch && $dispatchReview->requested_by!==auth()->id())
@foreach(['approve'=>'Approve this dispatch','reject'=>'Reject dispatch'] as $decision=>$label)
<form class="form-stack" method="POST" action="{{ route('shipments.approval.review',['shipment'=>$shipment->id,'decision'=>$decision]) }}">@csrf
<label class="field"><span>Independent review note</span>
<textarea name="reason" minlength="20" maxlength="1000" required rows="2" placeholder="Explain the documents and cargo details verified"></textarea></label>
<button class="{{ $decision==='approve'?'primary-button':'secondary-button' }}">{{ $label }}</button>
</form>
@endforeach
@endif
@if($dispatchReview->status==='rejected')
<p class="form-hint">Reopen the shipment, resolve the review issues and mark it ready again to request fresh approval.</p>
@endif
@endif
@endif
@if($canDispatch && (!$approvalRequired || $dispatchReview?->status==='approved'))
<form method="POST" action="{{ route('shipments.dispatch',$shipment->id) }}" class="form-stack" style="margin-top:17px">@csrf
<label class="field"><span>Actual departure date and time</span><input name="actual_departure_at" type="datetime-local" required value="{{ now($business->timezone)->format('Y-m-d\TH:i') }}"><small>Use the local time recorded by the carrier or exporter.</small></label>
<label class="check-row"><input type="checkbox" name="confirm_dispatch" value="1" required> I confirm that the physical cartons have been handed over for dispatch.</label>
<button class="primary-button">Record dispatch</button>
</form>
@else<p class="muted">A logistics lead or owner/administrator can record dispatch when the goods have physically left.</p>@endif
@elseif($shipment->status==='dispatched')
@if($canDispatch)
<form method="POST" action="{{ route('shipments.arrive',$shipment->id) }}" class="form-stack">@csrf
<label class="field"><span>Recorded arrival / delivery time</span><input name="actual_arrival_at" type="datetime-local" required value="{{ now($business->timezone)->format('Y-m-d\TH:i') }}"></label>
<label class="check-row"><input type="checkbox" name="confirm_arrival" value="1" required> I confirm delivery information has been received from the responsible party.</label>
<button class="primary-button">Record arrival / delivery</button></form>
@else<p class="muted">Waiting for a logistics lead to record the arrival.</p>@endif
@elseif($shipment->status==='delivered')<p class="muted">Shipment delivery has been recorded. Cartons remain linked permanently to this shipment.</p>
@else<p class="muted">This shipment is cancelled. Historical documents and events remain available.</p>
@endif
@if($canManage && in_array($shipment->status,['draft','ready'],true))
<form method="POST" action="{{ route('shipments.cancel',$shipment->id) }}" class="form-actions" style="margin-top:20px" data-confirm="Cancel shipment and return reserved cartons to packed stock?">@csrf
<input type="hidden" name="confirm_cancel" value="1">
<button class="secondary-button" type="submit">Cancel shipment and release cartons</button></form>
@endif
</section>
@if($canViewDocs)
<section class="section-card"><h2>Export paperwork</h2>
<p class="muted">Issue a packing list or a cargo handover note.</p>
@if($canGenerate && in_array($shipment->status,['ready','dispatched','delivered'],true))
@if($documentTemplates->isNotEmpty())
@foreach($documentTemplates as $template)
<div class="record"><div><strong>{{ $template->name }}</strong>
<small>{{ ucwords(str_replace('_',' ',$template->type)) }} · Version {{ $template->version }} · {{ $template->paper_size }} {{ $template->orientation }}</small></div>
<form method="POST" action="{{ route('export-documents.issue',['shipment'=>$shipment->id,'template'=>$template->id]) }}">@csrf
<button class="secondary-button">Issue document</button></form></div>
@endforeach
@else<p class="empty-msg">No document templates configured. <a class="action-link" href="{{ route('document-templates.index') }}">Create one</a>.</p>
@endif
@elseif($shipment->status==='draft')
<p class="notice-message">Mark the shipment ready before issuing official paperwork. Documents are not available while it remains a draft.</p>
@endif
<h2 style="margin-top:25px">Previously issued documents</h2>
@forelse($shipment->documents->sortByDesc('id') as $document)
<div class="record"><div><strong>{{ $document->reference }}</strong>
<small>{{ ucwords(str_replace('_',' ',$document->document_type)) }} · Issued {{ $document->created_at->format('d M Y H:i') }} · Version {{ $document->version }}</small></div>
<div class="form-actions"><a class="action-link" href="{{ route('export-documents.print',$document->id) }}" target="_blank" rel="noopener noreferrer">Print</a><a class="action-link" href="{{ route('export-documents.pdf',$document->id) }}">PDF</a></div>
</div>
@empty<p class="empty-msg">No documents issued yet.</p>@endforelse
@if($shipment->status==='cancelled' || $shipment->events->contains('action','reopened'))<p class="form-hint">This shipment was cancelled or reopened. Previously issued documents may not reflect its current carton allocation. Verify before sharing.</p>@endif
</section>
@endif
@if($canSeeInvoice)
<section class="section-card"><h2>Commercial invoice</h2>
@if($shipment->commercialInvoice)
<div class="record"><div><strong>{{ $shipment->commercialInvoice->reference }}</strong><small>{{ $shipment->commercialInvoice->currency }} {{ $shipment->commercialInvoice->grand_total }} · Not posted to financial ledger</small></div>
<a class="action-link" href="{{ route('commercial-invoices.show',$shipment->commercialInvoice->id) }}">View invoice</a></div>
@elseif($canInvoice && $shipment->sales_order_id && in_array($shipment->status,['ready','dispatched','delivered'],true))
<p class="muted">Invoice the allocated quantities at the agreed prices. Add freight and insurance if applicable.</p>
<form method="POST" action="{{ route('commercial-invoices.issue',$shipment->id) }}" class="form-stack">@csrf
<div class="form-columns"><label class="field"><span>Freight ({{ $shipment->salesOrder->currency }})</span><input name="freight_amount" type="number" min="0" step=".01" required value="0.00"></label>
<label class="field"><span>Insurance ({{ $shipment->salesOrder->currency }})</span><input name="insurance_amount" type="number" min="0" step=".01" required value="0.00"></label></div>
<label class="field"><span>Additional invoice note (optional)</span><textarea name="notes" rows="2" maxlength="2000"></textarea></label>
<div class="notice"><span>●</span><div><strong>Immutable after issuance</strong><p>Check prices, tax and charges before issuing. Issued invoices cannot be edited or reissued here.</p></div></div>
<button class="primary-button" data-confirm="Issue the commercial invoice now? Its contents cannot be edited afterwards.">Issue commercial invoice</button></form>
@else<p class="form-hint">Link a confirmed customer order and mark the shipment ready before issuing a commercial invoice.</p>
@endif
</section>
@endif
@if($canViewDocs)
<section class="section-card">
<h2 id="shipment-document-evidence">Export clearance and permit references</h2>
<div class="notice"><span>●</span><div><strong>Internal records are not government approvals</strong>
<p>Record official permit references. Verify validity and destination requirements with the issuing authority.</p></div></div>
@if($canGenerate && $shipment->status!=='cancelled')
<form method="POST" action="{{ route('shipments.clearance.submit',$shipment->id) }}" enctype="multipart/form-data" class="form-stack form-narrow">@csrf
<label class="field"><span>Document type</span><select name="document_kind" required>
@foreach($clearanceKinds as $kind=>$label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach
</select></label>
<div class="form-columns">
<label class="field"><span>Issuing authority shown on document</span><input name="authority_name" required minlength="3" maxlength="180" placeholder="Name on original certificate"></label>
<label class="field"><span>Official reference number on document</span><input name="external_reference" required minlength="3" maxlength="180"></label>
</div>
<label class="field"><span>Original supporting document (PDF/JPEG/PNG; up to 5 MB)</span><input name="file" type="file" accept="application/pdf,image/jpeg,image/png"></label>
<label class="field"><span>Expiration date (if applicable)</span><input type="date" name="valid_until"></label>
<label class="field"><span>Evidence and official verification steps</span><textarea name="source_evidence" required rows="3" minlength="30" maxlength="3000" placeholder="Identify the original document, issuing authority, inspection findings and where the reference may be verified independently."></textarea></label>
<button class="secondary-button">Submit document for separate internal review</button></form>
@endif
<h3>Recorded external documents</h3>
@forelse($clearanceChecks as $check)
<div class="record"><div><strong>{{ $clearanceKinds[$check->document_kind]??$check->document_kind }}</strong>
<small>{{ $check->authority_name }} · {{ $check->external_reference }}</small>
<small>{{ str_replace('_',' ',ucfirst($check->status)) }}@if($check->valid_until) · Expires {{ $check->valid_until->format('d M Y') }}@endif</small>
<small>{{ $check->source_evidence }}</small>
@if($check->file_path)<p><a href="{{ route('shipments.readiness.evidence',$check->id) }}">Download private original document</a></p>@endif
@if($canManageRequirements && $check->status==='internally_checked')<details><summary>Revoke this document review</summary><form method="POST" action="{{ route('shipments.readiness.revoke',$check->id) }}" class="form-stack">@csrf<input name="reason" minlength="20" maxlength="3000" required placeholder="Reason and source evidence"><button class="text-button">Revoke and retain history</button></form></details>@endif
@if($check->revocation_reason)<small>Revoked: {{ $check->revocation_reason }}</small>@endif
@if($check->review_note)<small>Review: {{ $check->review_note }}</small>@endif
</div>
@if($check->status==='pending_review' && $canCheckClearance && $check->submitted_by!==auth()->id())
<div class="form-stack">
@foreach(['accept'=>'Mark internally checked','reject'=>'Reject'] as $decision=>$label)
<form method="POST" action="{{ route('shipments.clearance.review',['shipment'=>$shipment->id,'record'=>$check->id,'action'=>$decision]) }}" class="form-stack">@csrf
<label class="field"><span>Independent review and external source-check note</span>
<input name="note" minlength="20" maxlength="2000" required placeholder="What original reference or authority website did you check?"></label>
<button class="secondary-button">{{ $label }}</button></form>
@endforeach
</div>
@endif</div>
@empty<p class="empty-msg">No export clearance references recorded for this shipment.</p>@endforelse
</section>
@endif
<section class="section-card"><h2>Carrier notifications</h2>
<p class="muted">Review carrier updates against the official AWB before recording dispatch or delivery.</p>
@forelse($providerCarrierEvents as $event)
<div class="record"><div><strong>{{ str_replace('_',' ',ucfirst($event->event_type)) }}</strong>
<small>AWB: {{ $event->external_reference }} · {{ $event->occurred_at->format('d M Y H:i') }}</small></div>
<span class="tiny-pill">{{ str_replace('_',' ',ucfirst($event->review_status)) }}</span></div>
@empty<p class="empty-msg">No carrier provider updates received.</p>@endforelse
@if(auth()->user() && $canManage)<a class="action-link" href="{{ route('integrations.index') }}">Review provider updates</a>@endif
</section>
<section class="section-card"><h2>Activity history</h2>
@foreach($shipment->events->sortByDesc('id') as $event)
<div class="record"><div><strong>{{ ucwords(str_replace('_',' ',$event->action)) }}</strong>
<small>{{ $event->actor?->name ?: 'System' }} · {{ $event->created_at->format('d M Y H:i') }}</small></div></div>
@endforeach
</section>
@endsection
