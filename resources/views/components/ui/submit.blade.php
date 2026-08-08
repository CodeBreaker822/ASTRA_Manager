@props(['variant' => 'default', 'size' => 'default', 'disabled' => false])

{{--
    Disables itself on submit so a slow POST can't be double-fired.

    Note: never put a Blade directive (@if, @disabled, ...) inside an <x-...>
    tag. Blade compiles the directive first and the component tag then fails to
    parse, silently emitting a literal <x-...> and unbalanced PHP. Build the
    attribute with a plain expression instead.
--}}
<x-ui.button
    type="submit"
    :variant="$variant"
    :size="$size"
    x-data="{ busy: false }"
    x-on:submit.window="busy = true"
    x-bind:disabled="busy || {{ $disabled ? 'true' : 'false' }}"
    {{ $attributes->merge($disabled ? ['disabled' => 'disabled'] : []) }}
>
    <svg x-cloak x-show="busy" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
    {{ $slot }}
</x-ui.button>
