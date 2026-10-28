<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#143f32">
    @php
        $pageTitle = trim($__env->yieldContent('title', 'Export & Business Management'));
        $pageDescription = trim($__env->yieldContent('description', 'Boosted One connects procurement, receiving, packing, traceable cartons, shipment documentation, customer orders and finance for Ugandan exporters and growing businesses.'));
        $pageRobots = trim($__env->yieldContent('robots', 'index,follow'));
        $nav = [
            ['name' => 'Home', 'route' => 'home', 'active' => request()->routeIs('home')],
            ['name' => 'Solutions', 'route' => 'site.solutions', 'active' => request()->routeIs('site.solution*')],
            ['name' => 'Features', 'route' => 'site.features', 'active' => request()->routeIs('site.feature*')],
            ['name' => 'How it works', 'route' => 'site.workflow', 'active' => request()->routeIs('site.workflow')],
            ['name' => 'Pricing', 'route' => 'site.pricing', 'active' => request()->routeIs('site.pricing')],
            ['name' => 'About', 'route' => 'site.about', 'active' => request()->routeIs('site.about')],
            ['name' => 'Contact', 'route' => 'site.contact', 'active' => request()->routeIs('site.contact')],
        ];
    @endphp
    <title>{{ $pageTitle }} · Boosted One ERP Uganda</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="{{ $pageRobots }}">
    <link rel="icon" type="image/svg+xml" href="/assets/boosted-mark.svg">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Boosted One">
    <meta property="og:title" content="{{ $pageTitle }} · Boosted One">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="stylesheet" href="{{ asset('assets/boosted-one.css') }}">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header">
    <div class="site-header-inner">
        <a class="site-brand" href="{{ route('home') }}" aria-label="Boosted One home">
            <x-brand-mark />
            <span class="brand-type"><strong>Boosted One</strong><small>BUSINESS & EXPORT ERP</small></span>
        </a>
        <nav class="primary-nav" aria-label="Main navigation">
            @foreach($nav as $link)
            <a href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" class="current" @endif>{{ $link['name'] }}</a>
            @endforeach
        </nav>
        <div class="header-actions">
            <a class="sign-in" href="{{ route('login') }}">Sign in</a>
            <a class="header-cta" href="{{ route('site.onboarding') }}" @if(request()->routeIs('site.onboarding')) aria-current="page" @endif>Get started <span aria-hidden="true">↗</span></a>
        </div>
        <details class="mobile-navigation">
            <summary aria-label="Open site menu">Menu <span aria-hidden="true">☰</span></summary>
            <nav aria-label="Mobile navigation">
                @foreach($nav as $link)
                    <a href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" class="current" @endif>{{ $link['name'] }}</a>
                @endforeach
                <a href="{{ route('site.onboarding') }}" @if(request()->routeIs('site.onboarding')) aria-current="page" @endif>Get started</a>
                <a href="{{ route('login') }}">Sign in</a>
            </nav>
        </details>
    </div>
</header>
<main id="main-content">
    @if(session('status'))<div class="feedback feedback-success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())
    <div class="feedback feedback-error" role="alert">
        <strong>Please check your details.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif
    @yield('content')
</main>
<footer class="site-footer">
    <div class="footer-grid">
        <div class="footer-about">
            <a class="site-brand footer-brand" href="{{ route('home') }}"><x-brand-mark /><span class="brand-type"><strong>Boosted One</strong><small>BUSINESS & EXPORT ERP</small></span></a>
            <p>Connecting the work behind every export—from suppliers and stock to packed cartons, shipments and business finances.</p>
            <p class="footer-company">A product of Boosted Technologies Limited · Uganda</p>
        </div>
        <div>
            <h2>Explore</h2>
            <a href="{{ route('site.solutions') }}">Business solutions</a>
            <a href="{{ route('site.features') }}">All features</a>
            <a href="{{ route('site.workflow') }}">How it works</a>
            <a href="{{ route('site.pricing') }}">Subscriptions</a>
        </div>
        <div>
            <h2>Company</h2>
            <a href="{{ route('site.about') }}">About Boosted One</a>
            <a href="{{ route('site.onboarding') }}">Request onboarding</a>
            <a href="{{ route('site.contact') }}">Contact us</a>
            <a href="{{ route('login') }}">Customer sign in</a>
        </div>
        <div>
            <h2>Policies</h2>
            <a href="{{ route('privacy') }}">Privacy policy</a>
            <a href="{{ route('retention') }}">Data retention</a>
            <a href="{{ route('terms') }}">Terms of service</a>
            <a href="mailto:boostedone@boostedtechs.com">boostedone@boostedtechs.com</a>
        </div>
    </div>
    <div class="footer-bottom"><small>© {{ date('Y') }} Boosted Technologies Limited. All rights reserved.</small><small>Built for Uganda. Ready for your next stage of growth.</small></div>
</footer>
</body>
</html>
