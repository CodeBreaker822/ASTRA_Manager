@props(['href'])

<a href="{{ $href }}"
   {{ $attributes->class('text-primary underline-offset-4 transition-colors hover:underline') }}>
    {{ $slot }}
</a>
