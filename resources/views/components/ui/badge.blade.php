@props(['variant' => 'default'])

<span {{ $attributes->class([
    config('ui.badge.base'),
    config('ui.badge.variants.'.$variant, config('ui.badge.variants.default')),
]) }}>{{ $slot }}</span>
