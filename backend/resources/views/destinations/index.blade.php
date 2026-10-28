@extends('layouts.console')
@section('title','Destinations')
@section('content')
<div class="page-intro"><div><span class="eyebrow">SALES</span><h1>Destinations</h1></div><a class="primary-button" data-remote-dialog href="{{ route('destinations.create') }}">Add destination</a></div>
<section class="section-card"><h2>Saved destinations</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Country</th><th>City</th><th>Location</th></tr></thead><tbody>@forelse($destinations as $destination)<tr><td>{{ $destination->name }}</td><td>{{ $destination->country_code ?: '—' }}</td><td>{{ $destination->city ?: '—' }}</td><td>{{ $destination->location ?: '—' }}</td></tr>@empty<tr><td colspan="4">No destinations yet.</td></tr>@endforelse</tbody></table></div>{{ $destinations->links() }}</section>
@endsection