<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#37664b">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Sign in') · Boosted One</title>

<link rel="stylesheet" href="/assets/expo.css?v={{ filemtime(public_path('assets/expo.css')) }}"><link rel="stylesheet" href="/assets/modules.css?v={{ filemtime(public_path('assets/modules.css')) }}">
<script src="/assets/theme-init.js"></script>
<link rel="stylesheet" href="/assets/workspace-ui.css?v={{ filemtime(public_path('assets/workspace-ui.css')) }}">
<link rel="stylesheet" href="/assets/workspace-tools.css?v={{ filemtime(public_path('assets/workspace-tools.css')) }}">
<x-pwa-meta />
</head><body data-error-dialog="{{ old('_ui_dialog') }}">
<div class="auth-shell auth-centered {{ request()->routeIs('businesses.choose') ? 'workspace-chooser' : '' }}">

  <main class="auth-main">
    <div class="auth-card">
      <a class="brand auth-mobile-brand" href="{{ route('home') }}" aria-label="Boosted One home"><x-brand-mark /><span><strong>Boosted One</strong><small>EXPORT ERP</small></span></a>
      @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="flash error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
      @yield('content')
      <x-pwa-install />
      <div class="auth-appearance"><button type="button" class="icon-button" id="theme-btn" aria-label="Toggle light and dark theme" title="Change theme"><x-ui-icon name="theme" /></button></div>
    </div>
  </main>
</div>
<script src="/assets/console-ui.js?v={{ filemtime(public_path('assets/console-ui.js')) }}" defer></script>
<script src="/assets/workspace-tools.js?v={{ filemtime(public_path('assets/workspace-tools.js')) }}" defer></script>
</body></html>
