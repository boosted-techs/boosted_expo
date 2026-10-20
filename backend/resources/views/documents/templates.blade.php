@extends('layouts.console')
@section('title','Document Templates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT DOCUMENTS</span><h1>Document templates</h1><p>Configure packing-list columns and dispatch-note details for each exporting business.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a class="secondary-button" href="{{ route('shipments.index') }}">Shipments</a><a class="secondary-button" href="{{ route('export-documents.index') }}">Issued documents</a></div>
<section class="section-card"><h2>Saved templates</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Template</th><th>Type</th><th>Page</th><th>Version</th>@if($canManage)<th></th>@endif</tr></thead>
<tbody>@forelse($templates as $t)
<tr><td><strong>{{ $t->name }}</strong></td><td>{{ $types[$t->type] ?? $t->type }}</td><td>{{ $t->paper_size }} {{ $t->orientation }}</td><td>{{ $t->version }}</td>
@if($canManage)<td><a class="action-link" href="{{ route('document-templates.edit',$t->id) }}">Edit</a></td>@endif</tr>
@empty<tr><td colspan="5" class="empty-msg">No document templates yet.</td></tr>@endforelse
</tbody></table></div></section>
@if($canManage)
<section class="section-card form-narrow"><h2>Create a document template</h2>
<form method="POST" action="{{ route('document-templates.store') }}" class="form-stack">@csrf
@php $template=null; @endphp
@include('documents._template_form')
<button class="primary-button">Save document template</button>
</form></section>
@else<p class="form-hint">Only company owners and administrators can change document templates.</p>@endif
@endsection