<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Overview') · Boosted One</title>
<link rel="preload" href="/assets/fonts/inter-v4.1-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/expo.css?v={{ filemtime(public_path('assets/expo.css')) }}"><link rel="stylesheet" href="/assets/modules.css?v={{ filemtime(public_path('assets/modules.css')) }}">
<x-pwa-meta />
<script src="/assets/theme-init.js"></script>
</head>
<body>
@php
$business = request()->attributes->get('activeBusiness');
$membership = request()->attributes->get('membership');
$access = app(\App\Support\BusinessPermissions::class);
$sections = config('expo.sections');
@endphp
<div class="shell">
<aside class="sidebar" id="sidebar">
  <a class="brand" href="{{ route('workspace') }}">
    <span class="brand-icon">B<span>↗</span></span>
    <span><strong>Boosted One</strong><small>EXPORT ERP</small></span>
  </a>
  <p class="nav-label">WORKSPACE</p><a class="footer-link" href="{{ route('business.subscription-status') }}">Account & support</a>
  <nav aria-label="Main navigation">
    <a href="{{ route('workspace') }}" class="{{ request()->routeIs('workspace')?'active':'' }}"><span class="nav-dot"></span>Today</a>
    @php($groups=[
      'Orders'=>[['Customer requests & quotations','sales-requests.index','sales.view'],['Order workspace','sales-orders.index','sales.orders.view'],['Shipments & transport','shipments.index','shipments.view'],['Commercial invoices','commercial-invoices.index','invoices.view'],['Export documents','export-documents.index','documents.view']],
      'Stock & Buying'=>[['Counts, moves & repacking','stock.exceptions.index','stock.view'],['Suppliers','suppliers.index','suppliers.view'],['Supplier requests','supply-requests.index','procurement.view'],['Purchases','purchases.index','purchases.view'],['Receiving','receiving.index','receipts.view'],['Who supplied what?','partners.receiving','receipts.view'],['Stock batches','stock.index','stock.view'],['Packing jobs','packing.index','packing.view'],['Cartons','cartons.index','cartons.view'],['Stickers & printing','labels.print','labels.view']],
      'Money'=>[['Trade, advances & accountability','finance.trade.index','finance.view'],['Cash, exchange & owner funds','finance.treasury.index','finance.view'],['Daily money','finance.money.index','finance.documents.view'],['Equipment & assets','finance.assets.index','finance.view'],['Loans & borrowing','finance.loans.index','finance.view'],['Business position','finance.position','finance.reports.view'],['Finance & ledger','finance.index','finance.view'],['Financial Reports','finance.reports.index','finance.reports.view'],['Bank reconciliation','finance.bank.index','finance.bank.view'],['Stock valuation','finance.inventory.index','finance.inventory.view'],['Credits & returns','finance.credits.index','finance.credits.view'],['Accounting controls','finance.controls','finance.controls.view'],['Payroll','finance.payroll.index','finance.payroll.view'],['Invoice handoff','invoice-accounting-handoff.index','finance.handoff.view'],['Payment corrections','finance.transaction-reversals.index','finance.payment.reversal.prepare'],['Receivables & payables FX','finance.monetary-revaluations.index','finance.monetary.revaluation.view'],['FX revaluation','finance.fx-revaluations.index','finance.fx.revaluation.view']],
      'People'=>[['Rota, attendance & work','people.index','staff.clock'],['My clock-in / clock-out','staff.index','staff.clock'],['System access','team.index','team.view']],
      'Business'=>[['Customers','customers.index','customers.view'],['Products','products.index','catalogue.view'],['Business settings','business.settings','business.settings'],['Sticker templates','labels.index','labels.view'],['Document templates','document-templates.index','documents.view'],['Provider updates','integrations.index','integrations.view'],['Release acceptance','release.acceptance.index','release.acceptance.view'],['Account & support','business.subscription-status','section.overview']]
    ])
    @foreach($groups as $label=>$links)
      @php($visible=collect($links)->filter(fn($link)=>$access->allows($membership,$link[2])))
      @if($visible->isNotEmpty())<details class="nav-workgroup" @if($visible->contains(fn($link)=>request()->routeIs($link[1]))) open @endif><summary>{{ $label }}</summary>@foreach($visible as $link)<a href="{{ route($link[1]) }}" class="{{ request()->routeIs($link[1])?'active':'' }}">{{ $link[0] }}</a>@endforeach</details>@endif
    @endforeach
  </nav>
  <div class="sidebar-footer">
    <strong>{{ $business->name }}</strong>
    <small>{{ $business->country_code }} · {{ $business->base_currency }} · {{ ucfirst($membership->role) }}</small>
    <a class="footer-link" href="{{ route('businesses.choose') }}">Switch business</a>
  </div>
</aside>
<div class="main">
<header class="topbar">
  <button class="mobile-menu" id="menu-btn" aria-label="Open navigation" aria-expanded="false">☰</button>
  <div class="crumb">Boosted One <span>/</span> <strong>@yield('title', 'Overview')</strong></div>
  <div class="top-actions">
    @if(config('app.env')!=='production')<span class="preview-pill">Development</span>@endif
    <button id="theme-btn" class="icon-button" aria-label="Toggle light and dark theme" title="Change theme">◐</button>
    <span class="username">{{ auth()->user()->name }}</span>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="signout" type="submit">Sign out</button></form>
  </div>
</header>
<main class="content">
  <x-pwa-install />
  @if(request()->attributes->get('platformEntitlement') && !request()->attributes->get('platformEntitlement')['can_write'])<div class="platform-warning">Subscription renewal is due. Existing records remain readable. <a href="{{ route('business.subscription-status') }}">Contact your owner / account support</a>.</div>@endif
  @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="flash error" role="alert"><strong>Some details need attention.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  @yield('content')
  <footer class="foot">Boosted Technologies · Export operations</footer>
</main>
</div></div>
<div class="scrim" id="scrim" hidden></div>
<script src="/assets/console-ui.js" defer></script>
@stack('scripts')
</body></html>
