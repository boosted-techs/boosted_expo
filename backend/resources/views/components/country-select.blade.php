@props(['name'=>'country_code','value'=>null,'required'=>false])
<select name="{{ $name }}" @required($required)><option value="">Choose country</option>@foreach(config('countries') as $code=>$country)<option value="{{ $code }}" @selected($value===$code)>{{ $country }} · {{ $code }}</option>@endforeach</select>
