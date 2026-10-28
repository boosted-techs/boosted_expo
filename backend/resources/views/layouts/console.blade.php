<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Overview') · Boosted One</title>

<link rel="stylesheet" href="/assets/expo.css?v={{ filemtime(public_path('assets/expo.css')) }}"><link rel="stylesheet" href="/assets/modules.css?v={{ filemtime(public_path('assets/modules.css')) }}">
<link rel="stylesheet" href="/assets/workspace-ui.css?v={{ filemtime(public_path('assets/workspace-ui.css')) }}">
<link rel="stylesheet" href="/assets/workspace-tools.css?v={{ filemtime(public_path('assets/workspace-tools.css')) }}">
<x-pwa-meta />
<script src="/assets/theme-init.js"></script>
</head>
<body data-error-dialog="{{ old('_ui_dialog') }}">
@php
$business = request()->attributes->get('activeBusiness');
$membership = request()->attributes->get('membership');
$access = app(\App\Support\BusinessPermissions::class);
$sections = config('expo.sections');
@endphp
<div class="shell">
<header class="topbar">
  <button class="mobile-menu" id="menu-btn" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar"><x-ui-icon name="menu" /></button>
  <a class="brand header-brand" href="{{ route('workspace') }}"><x-brand-mark /><span><strong>Boosted One</strong><small>BUSINESS WORKSPACE</small></span></a>
  <div class="top-actions">
    <span class="username">{{ auth()->user()->name }}</span>
    <button id="theme-btn" class="icon-button" aria-label="Toggle light and dark theme" title="Change theme"><x-ui-icon name="theme" /></button>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="signout" type="submit" aria-label="Sign out" title="Sign out"><x-ui-icon name="logout" /><span>Sign out</span></button></form>
  </div>
</header>

<aside class="sidebar" id="sidebar"><button type="button" class="icon-button sidebar-close" data-navigation-close aria-label="Close navigation">×</button>
  <a class="brand" href="{{ route('workspace') }}">
    <x-brand-mark />
    <span><strong>Boosted One</strong><small>EXPORT ERP</small></span>
  </a>
  <p class="nav-label">WORKSPACE</p>
  <nav aria-label="Main navigation">
    <a href="{{ route('workspace') }}" class="{{ request()->routeIs('workspace')?'active':'' }}"><x-ui-icon name="grid" />Overview</a>
    @php
    $groups=[
      'Orders'=>[['Customer requests & quotations','sales-requests.index','sales.view'],['Order workspace','sales-orders.index','sales.orders.view'],['Shipments & transport','shipments.index','shipments.view'],['Commercial invoices','commercial-invoices.index','invoices.view'],['Export documents','export-documents.index','documents.view']],
      'Stock & Buying'=>[['Counts, moves & repacking','stock.exceptions.index','stock.view'],['Suppliers','suppliers.index','suppliers.view'],['Supplier requests','supply-requests.index','procurement.view'],['Purchases','purchases.index','purchases.view'],['Receiving','receiving.index','receipts.view'],['Supply history','partners.receiving','receipts.view'],['Stock batches','stock.index','stock.view'],['Packing jobs','packing.index','packing.view'],['Cartons','cartons.index','cartons.view'],['Stickers & printing','labels.print','labels.view']],
      'Money'=>[['Buying & advances','finance.trade.index','finance.view'],['Cash & transfers','finance.treasury.index','finance.view'],['Daily money','finance.money.index','finance.documents.view'],['Equipment & assets','finance.assets.index','finance.view'],['Loans & borrowing','finance.loans.index','finance.view'],['Business position','finance.position','finance.reports.view'],['Finance & ledger','finance.index','finance.view'],['Financial Reports','finance.reports.index','finance.reports.view'],['Bank reconciliation','finance.bank.index','finance.bank.view'],['Stock valuation','finance.inventory.index','finance.inventory.view'],['Credits & returns','finance.credits.index','finance.credits.view'],['Accounting controls','finance.controls','finance.controls.view'],['Payroll','finance.payroll.index','finance.payroll.view'],['Invoice handoff','invoice-accounting-handoff.index','finance.handoff.view'],['Payment corrections','finance.transaction-reversals.index','finance.payment.reversal.prepare'],['Receivables & payables','finance.monetary-revaluations.index','finance.monetary.revaluation.view'],['FX revaluation','finance.fx-revaluations.index','finance.fx.revaluation.view']],
      'People'=>[['People & work','people.index','staff.clock'],['My clock-in / clock-out','staff.index','staff.clock'],['System access','team.index','team.view']],
      'Business'=>[['Customers','customers.index','customers.view'],['Products','products.index','catalogue.view'],['Business settings','business.settings','business.settings'],['Sticker templates','labels.index','labels.view'],['Document templates','document-templates.index','documents.view'],['Connected services','integrations.index','integrations.view'],['Readiness checks','release.acceptance.index','release.acceptance.view'],['Account & support','business.subscription-status','section.overview']]
    ];
    $routeFamily=explode('.',request()->route()?->getName()??'')[0];
    $activeGroup=match($routeFamily){
      'finance','invoice-accounting-handoff'=>'Money',
      'people','staff','team'=>'People',
      'stock','purchases','receiving','suppliers','supply-requests','packing','cartons','partners'=>'Stock & Buying',
      'sales-requests','sales-orders','shipments','commercial-invoices','export-documents'=>'Orders',
      'labels'=>request()->routeIs('labels.index','labels.edit')?'Business':'Stock & Buying',
      'products','customers','business','document-templates','integrations','release'=>'Business',
      default=>null,
    };
    @endphp
    @php($groupIcons=['Orders'=>'orders','Stock & Buying'=>'stock','Money'=>'money','People'=>'people','Business'=>'business'])
    @foreach($groups as $label=>$links)
      @php($visible=collect($links)->filter(fn($link)=>$access->allows($membership,$link[2])))
      @if($visible->isNotEmpty())<details class="nav-workgroup {{ $label===$activeGroup ? 'is-current' : ''  }}"><summary><x-ui-icon :name="$groupIcons[$label]" />{{ $label }}<x-ui-icon name="chevron" /></summary><div class="nav-dropdown">@foreach($visible as $link)
@php($children=collect((config('workspace-activities')[$link[1]]??[]))->filter(fn($child)=>$access->allows($membership,$child[2])))
@if($children->isNotEmpty())<details class="nav-children"><summary aria-label="{{ $link[0] }}">{{ $link[0] }}<x-ui-icon name="chevron" /></summary><div class="nav-child-list">@foreach($children as $child)<a aria-label="{{ $child[0] }}" href="{{ route($child[1],$child[3]) }}{{ isset($child[4])?'#'.$child[4]:'' }}">{{ $child[0] }}</a>@endforeach</div></details>
@else<a href="{{ route($link[1]) }}" class="{{ request()->routeIs($link[1])?'active':'' }}">{{ $link[0] }}</a>@endif
@endforeach</div></details>@endif
    @endforeach
  </nav>
  <div class="sidebar-footer">
    <strong>{{ $business->name }}</strong>
    <small>{{ $business->country_code }} · {{ $business->base_currency }} · {{ ucfirst($membership->role) }}</small>
    <a class="footer-link" href="{{ route('businesses.choose') }}">Switch business</a>
  </div>
</aside>
<div class="main">

<main class="content">
  <nav class="page-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('workspace') }}">Workspace</a><span aria-hidden="true">/</span><span aria-current="page">@yield('title', 'Overview')</span></nav>
  @if(request()->attributes->get('platformEntitlement') && !request()->attributes->get('platformEntitlement')['can_write'])<div class="platform-warning">Subscription renewal is due. Existing records remain readable. <a href="{{ route('business.subscription-status') }}">Contact your owner / account support</a>.</div>@endif
  @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="flash error" role="alert"><strong>Some details need attention.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <x-activity-navigation />
  @yield('content')
  <x-pwa-install />
</main>
<footer class="workspace-footer"><strong>Boosted One</strong><span>© {{ date('Y') }} Boosted Technologies · Business workspace</span></footer>
</div></div>
<div class="scrim" id="scrim" hidden></div>
<script src="/assets/console-ui.js?v={{ filemtime(public_path('assets/console-ui.js')) }}" defer></script>
@stack('scripts')
<script src="/assets/workspace-tools.js?v={{ filemtime(public_path('assets/workspace-tools.js')) }}" defer></script>
</body></html>
