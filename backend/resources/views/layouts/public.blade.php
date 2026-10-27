<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Sign in') · Boosted One</title>
<link rel="preload" href="/assets/fonts/inter-v4.1-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/expo.css?v={{ filemtime(public_path('assets/expo.css')) }}"><link rel="stylesheet" href="/assets/modules.css?v={{ filemtime(public_path('assets/modules.css')) }}">
<script src="/assets/theme-init.js"></script>
<x-pwa-meta />
</head><body>
<div class="auth-shell">
  <aside class="auth-side">
    <a class="brand" href="{{ route('login') }}"><span class="brand-icon">B<span>↗</span></span><span><strong>Boosted One</strong><small>EXPORT ERP</small></span></a>
    <div><span class="eyebrow">BUSINESS OPERATIONS</span><h1>From local markets to global destinations.</h1><p>A simpler way to coordinate purchasing, packing, shipments and business finances.</p></div>
    <small>© {{ date('Y') }} Boosted Technologies</small>
  </aside>
  <main class="auth-main">
    <div class="auth-card">
      <a class="brand auth-mobile-brand" href="{{ route('home') }}" aria-label="Boosted One home"><span class="brand-icon">B<span>↗</span></span><span><strong>Boosted One</strong><small>EXPORT ERP</small></span></a>
      @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="flash error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
      @yield('content')
      <x-pwa-install />
    </div>
  </main>
</div>
</body></html>
