@props(['for' => null])

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class('flex items-center gap-2 text-sm leading-none font-medium select-none') }}
>
    {{ $slot }}
</label>
