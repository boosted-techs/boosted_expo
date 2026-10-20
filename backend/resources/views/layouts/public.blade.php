<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Sign in') · Boosted Export ERP</title>
<link rel="stylesheet" href="/assets/expo.css"><link rel="stylesheet" href="/assets/modules.css">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('expo-theme')==='dark'?'dark':'light'}catch(e){}</script>
</head><body>
<div class="auth-shell">
  <aside class="auth-side">
    <a class="brand" href="{{ route('login') }}"><span class="brand-icon">B<span>↗</span></span><span><strong>Boosted Export</strong><small>EXPORT ERP</small></span></a>
    <div><span class="eyebrow">BUSINESS OPERATIONS</span><h1>From local markets to global destinations.</h1><p>A simpler way to coordinate purchasing, packing, shipments and business finances.</p></div>
    <small>© {{ date('Y') }} Boosted Technologies</small>
  </aside>
  <main class="auth-main">
    <div class="auth-card">
      @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="flash error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
      @yield('content')
    </div>
  </main>
</div>
</body></html>