@extends('layouts.console')
@section('title', $sections[$section][0])
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT OPERATIONS</span><h1>{{ $sections[$section][0] }}</h1><p>{{ $sections[$section][1] }}</p></div><div class="page-mark">EXP / 01</div></div>
@if($section === 'overview')
  <div class="notice"><span>●</span><div><strong>Workspace foundation is ready</strong><p>Purchasing, receiving, packing and shipment documentation have working tools. Dashboard totals remain placeholders until reporting and accounting are connected.</p></div></div>
  <div class="stats">
    @foreach([['Open customer orders', 'Sales'], ['Produce awaiting packing', 'Operations'], ['Upcoming shipments', 'Exports'], ['Outstanding approvals', 'Management']] as $card)
      <article class="stat"><span>{{ $card[0] }}</span><strong>—</strong><small>{{ $card[1] }} · not connected</small></article>
    @endforeach
  </div>
  <div class="grid-2">
    <section class="panel"><div class="panel-head"><div><span class="eyebrow">YOUR WORK</span><h2>Daily operations</h2></div></div><div class="task-grid">
    @foreach([
      ['Products','products.index','catalogue.view'],
      ['Customers','customers.index','customers.view'],
      ['Suppliers','suppliers.index','suppliers.view'],
      ['Purchases','purchases.index','purchases.view'],
      ['Receive produce','receiving.index','receipts.view'],
      ['Packing jobs','packing.index','packing.view'],
      ['Packed cartons','cartons.index','cartons.view'],
      ['Export shipments','shipments.index','shipments.view'],
      ['Export documents','export-documents.index','documents.view'],
      ['Sticker templates','labels.index','labels.view']
    ] as $task)
      @if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'), $task[2]))
      <a href="{{ route($task[1]) }}" class="task"><span class="task-sign">↗</span><strong>{{ $task[0] }}</strong><small>Open working tools</small></a>
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
      'purchasing' => [['Market purchases','Record market buying and sourcing costs'],['Walk-in suppliers','Record purchases without a supplier account'],['Supply requests','Collect quotations and select suppliers']],
      'operations' => [['Goods receiving','Record accepted and rejected incoming produce'],['Stock batches','Trace available produce and farmer origins'],['Packing jobs','Record grades, cartons and sorting losses']],
      'exports' => [['Shipment planning','Allocate completed cartons without double booking'],['Packing lists','Issue configurable buyer packing lists and dispatch notes'],['Dispatch tracking','Track flights, AWB numbers and manual milestones']],
      'finance' => [['Business accounts','General ledger, receivables and payables'],['Multiple currencies','Amounts and reporting currencies'],['Shipment profit','Allocate costs by shipment']],
      'staff' => [['Attendance','Record attendance and work shifts'],['Remuneration','Daily, monthly and piece-rate wages'],['Payroll','Approve and account for earnings']],
      'templates' => [['Sticker templates','Generate Zebra ZPL with selectable fields and shipment details'],['Product, batch & carton QR','Selectable scannable identities with visibility controls'],['Business documents','Configure packing-list columns and print PDF documents']],
      'settings' => [['Company profile','Locations, warehouses and business identity'],['Approvals','Configurable approval rules'],['Integrations','R2 storage, push and offline settings']],
    ][$section];
    $workingLinks = [
      'sales' => [['Customer directory','customers.index','customers.view']],
      'purchasing' => [
        ['Suppliers','suppliers.index','suppliers.view'],
        ['Supplier quotations','supply-requests.index','procurement.view'],
        ['Purchases','purchases.index','purchases.view'],
      ],
      'operations' => [
        ['Goods receiving','receiving.index','receipts.view'],
        ['Unpacked stock','stock.index','receipts.view'],
        ['Packing jobs','packing.index','packing.view'],
        ['Packed cartons','cartons.index','cartons.view'],
      ],
      'exports' => [
        ['Export shipments','shipments.index','shipments.view'],
        ['Packing lists and dispatch notes','export-documents.index','documents.view'],
        ['Document templates','document-templates.index','documents.view'],
      ],
      'templates' => [
        ['Sticker templates','labels.index','labels.view'],
        ['Export document templates','document-templates.index','documents.view'],
        ['Label history','labels.history','labels.generate'],
      ],
    ][$section] ?? [];
    $workingCards = [
      'sales'=>[0],'purchasing'=>[0,1,2],
      'operations'=>[0,1,2],'exports'=>[0,1,2],'templates'=>[0,1,2],
    ][$section] ?? [];
  @endphp
  @if(count($workingLinks))
    <div class="notice"><span>●</span><div><strong>Working tools available</strong><p>The links below open functional records and workflows. Some additional features and reports are still planned.</p></div></div>
    <div class="form-actions" style="margin-bottom:20px">
      @foreach($workingLinks as $link)
        @if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),$link[2]))
          <a class="secondary-button" href="{{ route($link[1]) }}">{{ $link[0] }} ↗</a>
        @endif
      @endforeach
    </div>
  @else
    <div class="notice"><span>●</span><div><strong>Planned tools</strong><p>This module describes future workflows. No transactions can be posted here yet.</p></div></div>
  @endif
  <div class="feature-grid">@foreach($detail as $index=>$item)
    <article class="feature"><span class="feature-bullet">↗</span><h2>{{ $item[0] }}</h2><p>{{ $item[1] }}</p><span class="coming">{{ in_array($index,$workingCards,true) ? 'First release' : 'Planned' }}</span></article>
  @endforeach</div>
@endif
@endsection