<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Overview') · Boosted Export ERP</title>
<link rel="stylesheet" href="/assets/expo.css"><link rel="stylesheet" href="/assets/modules.css">
<link rel="manifest" href="/manifest.webmanifest">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('expo-theme')==='dark'?'dark':'light'}catch(e){}</script>
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
    <span><strong>Boosted Export</strong><small>EXPORT ERP</small></span>
  </a>
  <p class="nav-label">WORKSPACE</p>
  <nav aria-label="Main navigation">
    @foreach($sections as $key => $details)
      @if($access->allows($membership, $details[2]))
      <a href="{{ route('workspace', ['section' => $key]) }}" class="{{ request()->is('workspace'.($key==='overview'?'':'/'.$key))?'active':'' }}" @if(request()->is('workspace'.($key==='overview'?'':'/'.$key))) aria-current="page" @endif>
        <span class="nav-dot"></span>{{ $details[0] }}
      </a>
      @endif
    @endforeach
    @if($access->allows($membership, 'customers.view'))
    <a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*')?'active':'' }}"><span class="nav-dot"></span>Customers</a>
    @endif
    @if($access->allows($membership, 'sales.view'))
    <a href="{{ route('sales-requests.index') }}" class="{{ request()->routeIs('sales-requests.*')||request()->routeIs('sales-quotes.*')?'active':'' }}"><span class="nav-dot"></span>Customer Requests & Quotes</a>
    @endif
    @if($access->allows($membership, 'sales.orders.view'))
    <a href="{{ route('sales-orders.index') }}" class="{{ request()->routeIs('sales-orders.*')?'active':'' }}"><span class="nav-dot"></span>Sales Orders</a>
    @endif
    @if($access->allows($membership, 'invoices.view'))
    <a href="{{ route('commercial-invoices.index') }}" class="{{ request()->routeIs('commercial-invoices.*')?'active':'' }}"><span class="nav-dot"></span>Commercial Invoices</a>
    @endif
    @if($access->allows($membership, 'finance.view'))
    <a href="{{ route('finance.index') }}" class="{{ request()->routeIs('finance.*')?'active':'' }}"><span class="nav-dot"></span>Finance & Ledger</a>
    @endif
    @if($access->allows($membership, 'finance.reports.view'))
    <a href="{{ route('finance.reports.index') }}" class="{{ request()->routeIs('finance.reports.*')?'active':'' }}"><span class="nav-dot"></span>Financial Reports</a>
    @endif
    @if($access->allows($membership, 'finance.bank.view'))
    <a href="{{ route('finance.bank.index') }}" class="{{ request()->routeIs('finance.bank.*')?'active':'' }}"><span class="nav-dot"></span>Bank Reconciliation</a>
    @endif
    @if($access->allows($membership, 'finance.inventory.view'))
    <a href="{{ route('finance.inventory.index') }}" class="{{ request()->routeIs('finance.inventory.*')?'active':'' }}"><span class="nav-dot"></span>Stock Valuation</a>
    @endif
    @if($access->allows($membership, 'finance.credits.view'))
    <a href="{{ route('finance.credits.index') }}" class="{{ request()->routeIs('finance.credits.*')?'active':'' }}"><span class="nav-dot"></span>Credit Notes</a>
    @endif
    @if($access->allows($membership, 'finance.controls.view'))
    <a href="{{ route('finance.controls') }}" class="{{ request()->routeIs('finance.controls*')?'active':'' }}"><span class="nav-dot"></span>Accounting Controls</a>
    @endif
    @if($access->allows($membership, 'finance.payroll.view'))
    <a href="{{ route('finance.payroll.index') }}" class="{{ request()->routeIs('finance.payroll.*')?'active':'' }}"><span class="nav-dot"></span>Staff Payroll</a>
    @endif
    @if($access->allows($membership, 'finance.monetary.revaluation.view'))
    <a href="{{ route('finance.monetary-revaluations.index') }}" class="{{ request()->routeIs('finance.monetary-revaluations.*')?'active':'' }}"><span class="nav-dot"></span>Receivables & Payables FX</a>
    @endif
    @if($access->allows($membership, 'finance.fx.revaluation.view'))
    <a href="{{ route('finance.fx-revaluations.index') }}" class="{{ request()->routeIs('finance.fx-revaluations.*')?'active':'' }}"><span class="nav-dot"></span>FX Revaluation</a>
    @endif
    @if($access->allows($membership, 'finance.payment.reversal.prepare'))
    <a href="{{ route('finance.transaction-reversals.index') }}" class="{{ request()->routeIs('finance.transaction-reversals.*')?'active':'' }}"><span class="nav-dot"></span>Payment Corrections</a>
    @endif
    @if($access->allows($membership, 'finance.handoff.view'))
    <a href="{{ route('invoice-accounting-handoff.index') }}" class="{{ request()->routeIs('invoice-accounting-handoff.*')?'active':'' }}"><span class="nav-dot"></span>Invoice Finance Handoff</a>
    @endif
    @if($access->allows($membership, 'suppliers.view'))
    <a href="{{ route('suppliers.index') }}" class="{{ request()->routeIs('suppliers.*')?'active':'' }}"><span class="nav-dot"></span>Suppliers</a>
    @endif
    @if($access->allows($membership, 'procurement.view'))
    <a href="{{ route('supply-requests.index') }}" class="{{ request()->routeIs('supply-requests.*')?'active':'' }}"><span class="nav-dot"></span>Supply Requests</a>
    @endif
    @if($access->allows($membership, 'purchases.view'))
    <a href="{{ route('purchases.index') }}" class="{{ request()->routeIs('purchases.*')?'active':'' }}"><span class="nav-dot"></span>Purchases</a>
    @endif
    @if($access->allows($membership, 'receipts.view'))
    <a href="{{ route('receiving.index') }}" class="{{ request()->routeIs('receiving.*')?'active':'' }}"><span class="nav-dot"></span>Goods Receiving</a>
    <a href="{{ route('stock.index') }}" class="{{ request()->routeIs('stock.*')?'active':'' }}"><span class="nav-dot"></span>Stock Batches</a>
    @endif
    @if($access->allows($membership, 'packing.view'))
    <a href="{{ route('packing.index') }}" class="{{ request()->routeIs('packing.*')?'active':'' }}"><span class="nav-dot"></span>Packing Jobs</a>
    @endif
    @if($access->allows($membership, 'cartons.view'))
    <a href="{{ route('cartons.index') }}" class="{{ request()->routeIs('cartons.*')?'active':'' }}"><span class="nav-dot"></span>Packed Cartons</a>
    @endif
    @if($access->allows($membership, 'shipments.view'))
    <a href="{{ route('shipments.index') }}" class="{{ request()->routeIs('shipments.*')?'active':'' }}"><span class="nav-dot"></span>Export Shipments</a>
    @endif
    @if($access->allows($membership, 'documents.view'))
    <a href="{{ route('export-documents.index') }}" class="{{ request()->routeIs('export-documents.*')?'active':'' }}"><span class="nav-dot"></span>Export Documents</a>
    <a href="{{ route('document-templates.index') }}" class="{{ request()->routeIs('document-templates.*')?'active':'' }}"><span class="nav-dot"></span>Document Templates</a>
    @endif
    @if($access->allows($membership, 'labels.view'))
    <a href="{{ route('labels.index') }}" class="{{ request()->routeIs('labels.*')?'active':'' }}"><span class="nav-dot"></span>Sticker Templates</a>
    @endif
    @if($access->allows($membership, 'catalogue.view'))
    <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*')?'active':'' }}"><span class="nav-dot"></span>Product Catalogue</a>
    @endif
    @if($access->allows($membership, 'team.view'))
    <a href="{{ route('team.index') }}" class="{{ request()->routeIs('team.*')?'active':'' }}"><span class="nav-dot"></span>Team & Access</a>
    @endif
    @if($access->allows($membership, 'business.settings'))
    <a href="{{ route('business.settings') }}" class="{{ request()->routeIs('business.settings*')?'active':'' }}"><span class="nav-dot"></span>Company Preferences</a>
    @endif
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
  <div class="crumb">Boosted Export <span>/</span> <strong>@yield('title', 'Overview')</strong></div>
  <div class="top-actions">
    <span class="preview-pill">Development</span>
    <button id="theme-btn" class="icon-button" aria-label="Toggle light and dark theme" title="Change theme">◐</button>
    <span class="username">{{ auth()->user()->name }}</span>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="signout" type="submit">Sign out</button></form>
  </div>
</header>
<main class="content">
  @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="flash error" role="alert"><strong>Some details need attention.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  @yield('content')
  <footer class="foot">Boosted Technologies · Export ERP development workspace</footer>
</main>
</div></div>
<div class="scrim" id="scrim" hidden></div>
<script>
const themeButton=document.getElementById('theme-btn');
themeButton?.addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=v;try{localStorage.setItem('expo-theme',v)}catch(e){}});
const side=document.getElementById('sidebar'),screen=document.getElementById('scrim'),menu=document.getElementById('menu-btn');
function showMenu(open){side.classList.toggle('open',open);screen.hidden=!open;menu.setAttribute('aria-expanded',String(open))}
menu.addEventListener('click',()=>showMenu(!side.classList.contains('open')));
screen.addEventListener('click',()=>showMenu(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')showMenu(false)});
</script>
</body></html>