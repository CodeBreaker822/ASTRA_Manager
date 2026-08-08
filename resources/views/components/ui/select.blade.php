@props(['name' => null, 'options' => [], 'selected' => null, 'id' => null])

<select
    @if ($name) name="{{ $name }}" @endif
    @if ($id ?? $name) id="{{ $id ?? $name }}" @endif
    @if (($name && $errors->has($name))) aria-invalid="true" @endif
    {{ $attributes->class([
        'h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100',
        'border-destructive ring-2 ring-red-100' => ($name && $errors->has($name)),
    ]) }}
>
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        @foreach ($options as $value => $label)
            <option value="{{ $value }}" @selected((string) $value === (string) $selected)>{{ $label }}</option>
        @endforeach
    @endif
</select>
