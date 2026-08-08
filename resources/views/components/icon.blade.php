@props(['name', 'fallback' => null])

@if ($paths = \App\Support\Icons::paths($name, $fallback))
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        {{ $attributes->class('size-4 shrink-0') }}
    >{!! $paths !!}</svg>
@endif
