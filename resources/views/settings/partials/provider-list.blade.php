@php
    $configuredProviders = collect($providers)->where('configured', true);
    $availableProviders = collect($providers)->where('configured', false);
@endphp

<section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ $title }}</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }} · {{ __('Drag to set fallback priority') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" onclick="openProviderLogs('{{ $category }}', '{{ $title }}')" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                {{ __('Log') }}
            </button>
            <button
                type="button"
                onclick="openProviderModal('{{ $category }}')"
                @disabled($availableProviders->isEmpty())
                class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-gray-600"
                title="{{ $availableProviders->isEmpty() ? __('All available providers have been added') : __('Add provider') }}"
            >
                <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                {{ __('Add') }}
            </button>
        </div>
    </div>

    <div class="provider-sortable divide-y divide-gray-200 dark:divide-gray-700" data-category="{{ $category }}">
        @forelse($configuredProviders as $provider)
            <div class="provider-row flex items-center gap-3 bg-white px-5 py-3 transition dark:bg-gray-800" data-provider="{{ $provider['setting_id'] }}">
                <span role="button" tabindex="0" draggable="true" class="provider-drag-handle shrink-0 cursor-grab touch-none select-none text-gray-400 hover:text-gray-600 active:cursor-grabbing dark:hover:text-gray-200" title="{{ __('Drag to change fallback priority') }}" aria-label="{{ __('Drag to reorder') }}">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M7 4a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 6a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm-1.5 7.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 4a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm-1.5 7.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm1.5 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                    </svg>
                </span>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                    {{ strtoupper(substr($provider['name'], 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $provider['name'] }}</h3>
                        <span
                            id="provider-health-{{ $provider['setting_id'] }}"
                            class="provider-health inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $provider['is_enabled'] ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}"
                            title="{{ $provider['is_enabled'] ? __('Checking provider and model availability') : __('This fallback row is disabled') }}"
                        >
                            {{ $provider['is_enabled'] ? __('Checking…') : __('Disabled') }}
                        </span>
                    </div>
                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $provider['model_label'] }}</p>
                </div>
                <button type="button" onclick="openEditProviderModal('{{ $provider['integration_key'] }}')" class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('Edit') }}
                </button>
            </div>
        @empty
            <div class="provider-empty-state px-5 py-10 text-center">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('No providers added yet') }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Use Add to connect the first provider.') }}</p>
            </div>
        @endforelse
    </div>
</section>
