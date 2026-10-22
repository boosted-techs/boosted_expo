@extends('layouts.console')
@section('title', 'Label Generation History')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ZEBRA LABELS</span><h1>Label file history</h1><p>Re-download the exact ZPL generated previously, even after changing a template.</p></div></div>
<div class="notice"><span>●</span><div><strong>File generation, not printer confirmation</strong><p>These records show when a label file was generated. They do not confirm that a physical Zebra printer received or printed it.</p></div></div>
<div class="form-actions" style="margin-bottom:18px"><a href="{{ route('labels.index') }}" class="secondary-button">Sticker templates</a><a href="{{ route('labels.printers') }}" class="secondary-button">Print workstations</a></div>
<section class="section-card">
<div class="table-wrap"><table class="data-table"><thead><tr><th>Generated</th><th>Carton</th><th>Template</th><th>Version</th><th>Action</th></tr></thead><tbody>
@forelse($generations as $entry)<tr>
<td>{{ $entry->created_at->format('d M Y H:i') }}</td><td>{{ $entry->carton?->carton_code ?? 'Unknown' }}</td><td>{{ $entry->template_snapshot['name'] ?? $entry->template?->name }}</td><td>{{ $entry->template_version }}</td>
<td><form method="POST" action="{{ route('labels.history.download',$entry->id) }}">@csrf<button class="secondary-button">Download original</button></form></td></tr>
@empty<tr><td colspan="5" class="empty-msg">No label files generated yet.</td></tr>@endforelse
</tbody></table></div>{{ $generations->links() }}
</section>
@endsection