@extends('layouts.console')
@section('title','Issued Export Documents')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT PAPERWORK</span><h1>Issued documents</h1><p>Access previous packing lists and dispatch notes as printable documents or PDF files.</p></div></div>
<div class="form-actions" style="margin-bottom:18px">
<a class="secondary-button" href="{{ route('shipments.index') }}">Shipments</a>
<a class="secondary-button" href="{{ route('document-templates.index') }}">Document templates</a>
</div>
<div class="notice"><span>●</span><div><strong>Issued copies are preserved</strong><p>Every document keeps its original carton details, buyer, weights, template configuration and issued status. Its saved PDF can be regenerated from the same immutable HTML snapshot. These are export paperwork drafts prepared by the company, not government-issued certificates.</p></div></div>
<section class="section-card"><h2>Document register</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Document reference</th><th>Shipment</th><th>Document</th><th>Issued</th><th>Version</th><th></th></tr></thead>
<tbody>@forelse($documents as $d)
<tr><td><strong>{{ $d->reference }}</strong></td>
<td><a class="action-link" href="{{ route('shipments.show',$d->shipment_id) }}">{{ $d->shipment->reference }}</a></td>
<td>{{ $d->document_type==='packing_list'?'Packing list':'Dispatch note' }}</td>
<td>{{ $d->created_at->format('d M Y H:i') }}</td><td>{{ $d->version }} (template {{ $d->template_version }})</td>
<td><div class="form-actions"><a class="action-link" target="_blank" rel="noopener noreferrer" href="{{ route('export-documents.print',$d->id) }}">Print</a>
<a class="action-link" href="{{ route('export-documents.pdf',$d->id) }}">Download PDF</a></div></td></tr>
@empty<tr><td colspan="6" class="empty-msg">No export documents issued yet. Mark a shipment ready before issuing paperwork.</td></tr>@endforelse
</tbody></table></div>{{ $documents->links() }}</section>
@endsection