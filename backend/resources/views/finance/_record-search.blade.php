<form method="GET" action="{{ url()->current() }}" class="form-stack" style="margin-bottom:12px">
@foreach(request()->except([$field, $page]) as $key => $value)
@if(is_scalar($value) && $key !== 'page' && !str_ends_with($key, '_page'))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
@endforeach
<label class="field"><span>{{ $label }}</span><input type="search" name="{{ $field }}" value="{{ request($field) }}" maxlength="120"></label>
<button class="secondary-button">Find records</button>
</form>
