@extends('layouts.console')
@section('title', $sections[$section][0])
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT OPERATIONS</span><h1>{{ $sections[$section][0] }}</h1><p>{{ $sections[$section][1] }}</p></div><div class="page-mark">EXP / 01</div></div>
@if($section === 'overview')
  <div class="notice"><span>●</span><div><strong>Workspace foundation is ready</strong><p>Business sign-in and company access are enabled. The summary below remains a preview until purchasing, packing and financial transactions are connected.</p></div></div>
  <div class="stats">
    @foreach([['Open customer orders', 'Sales'], ['Produce awaiting packing', 'Operations'], ['Upcoming shipments', 'Exports'], ['Outstanding approvals', 'Management']] as $card)
      <article class="stat"><span>{{ $card[0] }}</span><strong>—</strong><small>{{ $card[1] }} · not connected</small></article>
    @endforeach
  </div>
  <div class="grid-2">
    <section class="panel"><div class="panel-head"><div><span class="eyebrow">YOUR WORK</span><h2>Daily operations</h2></div></div><div class="task-grid">
    @foreach([['View products','products.index'],['Plan purchases','workspace','purchasing'],['Receive produce','workspace','operations'],['Prepare shipment','workspace','exports'],['Print labels','workspace','templates'],['Review finances','workspace','finance']] as $task)
      @php $key = $task[2] ?? null; @endphp
      @if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'), $key===null ? 'catalogue.view' : $sections[$key][2]))
      <a href="{{ $key ? route('workspace', ['section'=>$key]) : route($task[1]) }}" class="task"><span class="task-sign">↗</span><strong>{{ $task[0] }}</strong><small>{{ $key ? 'Module planning' : 'Open catalogue' }}</small></a>
      @endif
    @endforeach
    </div></section>
    <section class="panel"><div class="panel-head"><div><span class="eyebrow">PROCESS</span><h2>One connected workflow</h2></div></div><ol class="steps">
    <li>Customer request and quotation</li><li>Local sourcing and purchasing</li><li>Receiving and quality checks</li><li>Packing and labelling</li><li>Export dispatch and accounting</li></ol></section>
  </div>
@else
  @php
    $detail = [
      'sales' => [['Customer directory','Manage known international buyers'],['Requests & quotations','Customer price negotiations and revisions'],['Confirmed orders','Plan fulfilment across shipments']],
      'purchasing' => [['Market purchases','Local buying and cash advances'],['Walk-in suppliers','Receive without purchase orders'],['Supply requests','Compare and approve offers']],
      'operations' => [['Goods receiving','Weigh and grade incoming produce'],['Stock batches','Trace supplier and farmer origins'],['Packing jobs','Reconcile cartons and losses']],
      'exports' => [['Shipment planning','Allocate cartons and track fulfilment'],['Packing lists','Buyer-specific printable documents'],['Dispatch tracking','AWB, flights and shipping milestones']],
      'finance' => [['Business accounts','General ledger, receivables and payables'],['Multiple currencies','Amounts and reporting currencies'],['Shipment profit','Allocate costs by shipment']],
      'staff' => [['Attendance','Record attendance and work shifts'],['Remuneration','Daily, monthly and piece-rate wages'],['Payroll','Approve and account for earnings']],
      'templates' => [['Sticker designer','Custom Zebra 100 × 50 mm labels'],['Product, batch & carton QR','Selectable identity on each template'],['Business documents','Tables, logos and tracking fields']],
      'settings' => [['Company profile','Locations, warehouses and business identity'],['Approvals','Configurable approval rules'],['Integrations','R2 storage, push and offline settings']],
    ][$section];
  @endphp
  <div class="notice"><span>●</span><div><strong>Coming in a development pass</strong><p>This module describes intended workflows. It does not yet save transactions or generate operational reports.</p></div></div>
  <div class="feature-grid">@foreach($detail as $item)
    <article class="feature"><span class="feature-bullet">↗</span><h2>{{ $item[0] }}</h2><p>{{ $item[1] }}</p><span class="coming">Planned</span></article>
  @endforeach</div>
@endif
@endsection