@props(['name'])

@error($name)
    <p {{ $attributes->class('text-sm font-medium text-destructive') }}>{{ $message }}</p>
@enderror
