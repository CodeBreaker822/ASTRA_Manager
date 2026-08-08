<x-layouts.settings title="Appearance settings">
    <h1 class="sr-only">Appearance settings</h1>

    <div class="space-y-6">
        <x-ui.heading
            variant="small"
            title="Appearance settings"
            description="Update the appearance settings for your account"
        />

        {{-- Theme lives in localStorage, so the active tab is resolved client-side. --}}
        <div
            class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
            x-data="{ appearance: window.storedTheme() }"
        >
            @foreach ([['light', 'sun', 'Light'], ['dark', 'moon', 'Dark'], ['system', 'monitor', 'System']] as [$value, $icon, $label])
                <button
                    type="button"
                    x-on:click="appearance = '{{ $value }}'; window.setTheme('{{ $value }}')"
                    class="flex items-center rounded-md px-3.5 py-1.5 transition-colors"
                    x-bind:class="appearance === '{{ $value }}'
                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60'"
                >
                    <x-icon name="{{ $icon }}" class="-ml-1 h-4 w-4" />
                    <span class="ml-1.5 text-sm">{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </div>
</x-layouts.settings>
