@props(['value' => null, 'historical' => false, 'retain' => null])
@php
    $currencyRules = app(\App\Support\BusinessCurrencies::class);
    $currencyBusiness = request()->attributes->get('activeBusiness');
    $currencyOptions = $historical ? $currencyRules->catalogue()
        : array_intersect_key($currencyRules->catalogue(), array_flip($currencyRules->enabled($currencyBusiness)));
    if ($retain && isset($currencyRules->catalogue()[$retain])) {
        $currencyOptions[$retain] = $currencyRules->catalogue()[$retain];
    }
@endphp
<select name="currency" required {{ $attributes }}>
<option value="">Choose currency</option>
@foreach($currencyOptions as $currencyCode => $currencyName)
<option value="{{ $currencyCode }}" @selected($value === $currencyCode)>{{ $currencyCode }} · {{ $currencyName }}</option>
@endforeach
</select>
