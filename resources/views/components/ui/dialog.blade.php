@props(['open' => false])

{{--
    Alpine dialog. `trigger` opens it; inside the panel, `$dispatch('close-dialog')`
    or a [data-dialog-close] element closes it.
--}}
<div
    x-data="{ open: {{ $open ? 'true' : 'false' }} }"
    x-on:close-dialog.stop="open = false"
    x-on:keydown.escape.window="open = false"
    {{ $attributes }}
>
    @isset($trigger)
        <div x-on:click="open = true">{{ $trigger }}</div>
    @endisset

    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-50 grid place-items-center p-4">
            <div
                class="fixed inset-0 bg-black/50"
                x-on:click="open = false"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-end="opacity-0"
            ></div>

            <div
                role="dialog"
                aria-modal="true"
                x-trap.noscroll="open"
                x-on:click="if ($event.target.closest('[data-dialog-close]')) open = false"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative z-10 w-full max-w-lg rounded-xl border border-slate-200 bg-background p-6 shadow-lg"
            >
                {{ $slot }}
            </div>
        </div>
    </template>
</div>
