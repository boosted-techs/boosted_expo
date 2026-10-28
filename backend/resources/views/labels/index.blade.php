@extends('layouts.console')
@section('title', 'Sticker templates')
@section('content')
<div class="page-intro"><div><span class="eyebrow">STICKERS</span><h1>Sticker templates</h1><p>Use a saved template, preview the actual cartons, then print.</p></div>@if($canManage)<button type="button" class="primary-button" data-modal-open="create-template-form">Create template</button>@endif</div>
@if($canManage)<dialog class="workspace-dialog dialog-wide" id="create-template-form" aria-labelledby="create-template-title"><div class="dialog-heading"><h2 id="create-template-title">Create template</h2><button class="icon-button" type="button" data-modal-close aria-label="Close template designer">×</button></div><div class="dialog-body"><form method="POST" action="{{ route('labels.store') }}" enctype="multipart/form-data" class="form-stack">@csrf @include('labels._template-form')</form></div></dialog>@endif
<section class="section-card"><h2>Saved templates</h2>
<div class="table-wrap"><table class="data-table"><thead><tr><th data-sort="name">Template</th><th data-sort="width_mm">Size</th><th data-sort="dpi">Resolution</th><th>Version</th><th data-sort="qr_visibility">QR access</th><th>Actions</th></tr></thead><tbody>@forelse($templates as $entry)<tr><td><strong>{{ $entry->name }}</strong></td><td>{{ $entry->width_mm }} × {{ $entry->height_mm }} mm</td><td>{{ $entry->dpi }} dpi</td><td>{{ $entry->version }}</td><td>{{ $entry->qr_visibility==='internal'?'Staff only':($entry->qr_visibility==='protected'?'Secret PIN':'Public page') }}</td><td><a class="secondary-button" data-remote-dialog href="{{ route('labels.print',['template'=>$entry->id]) }}">Print / preview</a>@if($canManage)<a class="secondary-button" href="{{ route('labels.edit',$entry->id) }}">Edit</a>@endif</td></tr>@empty<tr><td colspan="6">No saved templates.</td></tr>@endforelse</tbody></table></div>{{ $templates->links() }}
</section>

@endsection
