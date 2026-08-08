@props(['align' => 'end', 'width' => 'w-56'])

<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <div x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="true">
        {{ $trigger }}
    </div>

    <div
        x-cloak
        x-show="open"
        x-on:click.outside="open = false"
        x-trap="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-50 mt-2 {{ config('ui.dropdown.alignments.'.$align, config('ui.dropdown.alignments.end')) }} {{ $width }} overflow-hidden rounded-lg border border-slate-200 bg-popover p-1 text-popover-foreground shadow-md"
        role="menu"
    >
        {{ $slot }}
    </div>
</div>
