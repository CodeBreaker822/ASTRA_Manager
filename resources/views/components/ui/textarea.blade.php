@props(['name' => null, 'rows' => 3, 'id' => null])

<textarea
    @if ($name) name="{{ $name }}" @endif
    @if ($id ?? $name) id="{{ $id ?? $name }}" @endif
    rows="{{ $rows }}"
    @if (($name && $errors->has($name))) aria-invalid="true" @endif
    {{ $attributes->class([
        'rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100',
        'border-destructive ring-2 ring-red-100' => ($name && $errors->has($name)),
    ]) }}
>{{ $slot }}</textarea>
