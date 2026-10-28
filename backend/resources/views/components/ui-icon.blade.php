@props(['name' => 'grid'])
<svg {{ $attributes->merge(['class' => 'ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('orders')<path d="M8 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-2M8 3h8v4H8zM8 12h8M8 16h5"/>@break
@case('stock')<path d="m3 7 9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7M12 11v10M7 5l10 4"/>@break
@case('money')<rect x="3" y="5" width="18" height="14" rx="3"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>@break
@case('people')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-3-5"/>@break
@case('business')<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-5h6v5M8 7h2M14 7h2M8 11h2M14 11h2"/>@break
@case('print')<path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z"/>@break
@case('search')<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>@break
@case('logout')<path d="M9 4H5v16h4M14 8l4 4-4 4M9 12h12"/>@break
@case('theme')<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor" stroke="none"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('chevron')<path d="m8 10 4 4 4-4"/>@break
@case('arrow')<path d="M5 12h14m-5-5 5 5-5 5"/>@break
@case('health')<path d="M3 12h4l3-8 4 16 3-8h4"/>@break
@case('settings')<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="var(--panel)"/><circle cx="15" cy="17" r="3" fill="var(--panel)"/>@break
@case('support')<path d="M4 14v-3a8 8 0 0 1 16 0v3M4 12H2v6h5v-6H4Zm16 0h2v6h-5v-6h3ZM20 18v3h-8"/>@break
@default<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
@endswitch
</svg>
