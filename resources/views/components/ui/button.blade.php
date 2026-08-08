@props([
    'variant' => 'default',
    'size' => 'default',
    'href' => null,
    'type' => 'button',
])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([
        config('ui.button.base'),
        config('ui.button.variants.'.$variant, config('ui.button.variants.default')),
        config('ui.button.sizes.'.$size, config('ui.button.sizes.default')),
    ]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class([
        config('ui.button.base'),
        config('ui.button.variants.'.$variant, config('ui.button.variants.default')),
        config('ui.button.sizes.'.$size, config('ui.button.sizes.default')),
    ]) }}>{{ $slot }}</button>
@endif
