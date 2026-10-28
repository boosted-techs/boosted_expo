@extends('layouts.console')
@section('title', $sections[$section][0])
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXPORT OPERATIONS</span><h1>{{ $sections[$section][0] }}</h1><p>{{ $sections[$section][1] }}</p></div><div class="page-mark">{{ now('Africa/Kampala')->format('D, d M Y') }}</div></div>
@if($section === 'overview')

  <div class="overview-dashboard">
  <div class="stats">
    @forelse($metrics as $card)
      <a class="stat" href="{{ route($card['route']) }}">
        <span>{{ $card['label'] }}</span>
        <strong>{{ number_format($card['value']) }}</strong>
        <small>{{ $card['detail'] }}</small>
      </a>
    @empty
      <article class="stat"><span>Welcome</span><strong>✓</strong><small>Your permitted tools are available below.</small></article>
    @endforelse
  </div>
  @include('workspace-charts')
  @include('people._today')
  </div>
  <div class="dashboard-tools">
    <section class="panel"><div class="panel-head"><div><span class="eyebrow">YOUR WORK</span><h2>Daily operations</h2></div></div><div class="task-grid">
    @foreach([
      ['Products','products.index','catalogue.view'],
      ['Customers','customers.index','customers.view'],
      ['Customer quotations','sales-requests.index','sales.view'],
      ['Confirmed orders','sales-orders.index','sales.orders.view'],
      ['Commercial invoices','commercial-invoices.index','invoices.view'],
      ['Finance & ledger','finance.index','finance.view'],
      ['Bank reconciliation','finance.bank.index','finance.bank.view'],
      ['Inventory valuation','finance.inventory.index','finance.inventory.view'],
      ['Finance reports','finance.reports.index','finance.reports.view'],
      ['Accounting controls','finance.controls','finance.controls.view'],
      ['Payroll runs','finance.payroll.index','finance.payroll.view'],
      ['Shifts & attendance','staff.index','staff.clock'],
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
      <a href="{{ route($task[1]) }}" class="task"><span class="task-sign" aria-hidden="true">↗</span><strong>{{ $task[0] }}</strong></a>
      @endif
    @endforeach
    </div></section>
    <section class="panel workflow-panel"><div class="panel-head"><div><span class="eyebrow">WORKFLOW</span><h2>From order to export</h2></div></div><ol class="steps">
    <li>Customer request and quotation</li><li>Local sourcing and purchasing</li><li>Receiving and quality checks</li><li>Packing and labelling</li><li>Export dispatch and accounting</li></ol></section>
  </div>
@else
  @php
    $detail = [
      'sales' => [['Customer directory','Manage known international buyers'],['Requests & quotations','Customer price negotiations and revisions'],['Confirmed orders','Plan fulfilment across shipments']],
      'purchasing' => [['Market purchases','Record market buying and sourcing costs'],['Walk-in suppliers','Record purchases without a supplier account'],['Supply requests','Collect quotations and select suppliers']],
      'operations' => [['Goods receiving','Record accepted and rejected incoming produce'],['Stock batches','Trace available produce and farmer origins'],['Packing jobs','Record grades, cartons and sorting losses']],
      'exports' => [['Shipment planning','Allocate completed cartons without double booking'],['Packing lists','Issue configurable buyer packing lists and dispatch notes'],['Dispatch tracking','Track flights, AWB numbers and manual milestones']],
      'finance' => [['Business accounts','Balanced journals, company chart and posting approvals'],['Multiple currencies','Approved historical conversion rates and foreign-exchange differences'],['Shipment contribution','Documented costs and provisional margin analysis']],
      'staff' => [['Attendance','Record attendance and work shifts'],['Remuneration','Daily, monthly and piece-rate wages'],['Payroll','Approve and account for earnings']],
      'templates' => [['Sticker templates','Generate Zebra ZPL with selectable fields and shipment details'],['Product, batch & carton QR','Selectable scannable identities with visibility controls'],['Business documents','Configure packing-list columns and print PDF documents']],
      'settings' => [['Company profile','Locations, warehouses and business identity'],['Approvals','Configurable approval rules'],['Integrations','R2 storage, push and offline settings']],
    ][$section];
    $workingLinks = [
      'sales' => [['Customers','customers.index','customers.view'],['Customer requests & quotations','sales-requests.index','sales.view'],['Confirmed sales orders','sales-orders.index','sales.orders.view'],['Commercial invoices','commercial-invoices.index','invoices.view']],
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
      'finance' => [
        ['Finance dashboard','finance.index','finance.view'],
        ['Supplier bills, payments and shipment costs','finance.documents','finance.documents.view'],
        ['Approvals and posting queue','finance.reviews','finance.reviews.view'],
        ['Exchange rates','finance.rates','finance.rates.view'],
        ['Bank reconciliation','finance.bank.index','finance.bank.view'],
        ['Opening, banks and period controls','finance.controls','finance.controls.view'],
        ['Payment corrections','finance.transaction-reversals.index','finance.payment.reversal.prepare'],
        ['Foreign bank revaluation','finance.fx-revaluations.index','finance.fx.revaluation.view'],
        ['Receivables & Payables FX','finance.monetary-revaluations.index','finance.monetary.revaluation.view'],
        ['Staff payroll','finance.payroll.index','finance.payroll.view'],
      ],
      'staff' => [
        ['Shifts and attendance','staff.index','staff.clock'],
        ['Prepare payroll','finance.payroll.index','finance.payroll.view'],
      ],
      'settings' => [
        ['Company preferences and approvals','business.settings','business.settings'],
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
      'sales'=>[0,1,2],'purchasing'=>[0,1,2],
      'operations'=>[0,1,2],'exports'=>[0,1,2],
      'finance'=>[0,1],'staff'=>[0,2],'templates'=>[0,1,2],
      'settings'=>[1],
    ][$section] ?? [];
  @endphp
  @if(count($workingLinks))
    <div class="notice"><span>●</span><div><strong>Quick actions</strong><p>Choose a tool to continue.</p></div></div>
    <div class="form-actions" style="margin-bottom:20px">
      @foreach($workingLinks as $link)
        @if(app(\App\Support\BusinessPermissions::class)->allows(request()->attributes->get('membership'),$link[2]))
          <a class="secondary-button" href="{{ route($link[1]) }}">{{ $link[0] }} ↗</a>
        @endif
      @endforeach
    </div>
  @else
    <div class="notice"><span>●</span><div><strong>No tools available</strong><p>Contact your administrator for access.</p></div></div>
  @endif
  <div class="feature-grid">@foreach($detail as $index=>$item)
    @if(in_array($index,$workingCards,true))<article class="feature"><span class="feature-bullet">↗</span><h2>{{ $item[0] }}</h2><p>{{ $item[1] }}</p></article>@endif
  @endforeach</div>
@endif
@endsection