<x-layouts.app>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('Settings') }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Manage your system preferences') }}</p>
            </div>

            @include('settings.partials.system-navigation')

            <!-- API Manager Content -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ __('API Manager') }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Manage license keys and permissions for external applications') }}</p>
                        </div>
                        <button type="button" onclick="openApiModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            {{ __('Add New API') }}
                        </button>
                    </div>
                    
                    <!-- API Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('App Name') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('License Key') }}</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">POST</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">GET</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">PUT</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">PATCH</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">DELETE</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($apis as $api)
                                <tr data-id="{{ $api->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $api->app_name }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-2">
                                            <div class="text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                                ••••••••••••••••••••••••••••••••
                                            </div>
                                            <button type="button" onclick="showTokenModal({{ $api->id }}, '{{ $api->app_token }}')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition duration-150" title="Show license key">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer http-method-toggle" data-method="post" {{ $api->can_post ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer http-method-toggle" data-method="get" {{ $api->can_get ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer http-method-toggle" data-method="put" {{ $api->can_put ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer http-method-toggle" data-method="patch" {{ $api->can_patch ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer http-method-toggle" data-method="delete" {{ $api->can_delete ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer status-toggle" {{ $api->is_active ? 'checked' : '' }}>
                                            <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-5 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                                        </label>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <div class="flex justify-center space-x-2">
                                            @can('delete-api_manager')
                                            <button class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 transition duration-150 ease-in-out" title="Delete">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('No license keys found. Click "Add New API" to create one.') }}
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Transcription Provider Settings -->
            @php
                $providerGroups = collect($transcriptionProviders)->groupBy('category');
            @endphp
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
                @include('settings.partials.provider-list', [
                    'category' => 'transcriber',
                    'title' => __('Transcribers'),
                    'description' => __('Speech-to-text providers'),
                    'providers' => $providerGroups->get('transcriber', collect()),
                ])

                @include('settings.partials.provider-list', [
                    'category' => 'text_fixer',
                    'title' => __('Text Fixers'),
                    'description' => __('Transcript polishing and cleanup providers'),
                    'providers' => $providerGroups->get('text_fixer', collect()),
                ])
            </div>

            <!-- Transcriber App Package -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-6">
                    <div class="mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ __('Transcriber App Package') }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Publish the ZIP package and version offered to licensed Transcriber clients') }}</p>
                    </div>

                    <form id="transcriberPackageForm" enctype="multipart/form-data">
                        @csrf
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                            <div>
                                <label for="transcriberVersion" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Version') }}</label>
                                <input
                                    id="transcriberVersion"
                                    type="text"
                                    name="version"
                                    value="{{ $transcriberPackage['version'] ?? '' }}"
                                    required
                                    maxlength="50"
                                    placeholder="1.2.0"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                >
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Use the version reported to clients, for example 1.2.0.') }}</p>
                            </div>

                            <div class="lg:col-span-2">
                                <label for="transcriberPackage" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Upload package') }}</label>
                                <label id="transcriberPackageDropzone" for="transcriberPackage" class="mt-1 flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-5 text-center transition hover:border-blue-400 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-900 dark:hover:border-blue-500 dark:hover:bg-gray-700">
                                    <div id="transcriberPackageIdle" class="flex flex-col items-center">
                                        <svg class="mb-2 h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5.5 5.5 0 0116.9 6L17 6a5 5 0 011 9.9M12 12v9m0-9l-3 3m3-3l3 3"></path>
                                        </svg>
                                        <span id="transcriberPackageLabel" class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Choose a ZIP file or drag and drop it here') }}</span>
                                        <span class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ZIP only, up to 500 MB') }}</span>
                                    </div>
                                    <div id="transcriberPackageProgress" class="hidden w-full max-w-md" role="status" aria-live="polite">
                                        <div class="flex items-center justify-between text-sm font-medium text-gray-700 dark:text-gray-200">
                                            <span id="transcriberPackageProgressText">{{ __('Uploading package...') }}</span>
                                            <span id="transcriberPackageProgressPercent">0%</span>
                                        </div>
                                        <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                            <div id="transcriberPackageProgressBar" class="h-full w-0 rounded-full bg-blue-600 transition-all duration-200"></div>
                                        </div>
                                        <p id="transcriberPackageProgressFilename" class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400"></p>
                                    </div>
                                    <input id="transcriberPackage" type="file" accept=".zip,application/zip" class="sr-only">
                                </label>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                @if($transcriberPackage['zipfile'])
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ __('Current package:') }}</span>
                                    <span class="font-mono">{{ $transcriberPackage['zipfile'] }}</span>
                                    @if($transcriberPackage['version'])
                                        <span class="ml-2 inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">v{{ $transcriberPackage['version'] }}</span>
                                    @endif
                                @else
                                    {{ __('No Transcriber App Package has been uploaded yet.') }}
                                @endif
                            </div>
                            <button type="submit" form="transcriberPackageForm" class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5.5 5.5 0 0116.9 6L17 6a5 5 0 011 9.9M12 12v9m0-9l-3 3m3-3l3 3"></path>
                                </svg>
                                {{ __('Upload Package') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @include('settings.modal.add-api')

<!-- Provider Modal -->
<div id="providerModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50" role="dialog" aria-modal="true" aria-labelledby="providerModalTitle">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <form id="providerForm">
                @csrf
                <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <div>
                        <h3 id="providerModalTitle" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Add Provider') }}</h3>
                        <p id="providerModalDescription" class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Connect an available provider with an API key.') }}</p>
                    </div>
                    <button type="button" onclick="closeProviderModal()" class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('Close') }}">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <div>
                        <label for="providerSelect" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Provider') }}</label>
                        <select id="providerSelect" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"></select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label id="providerApiKeyLabel" for="providerApiKey" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('API Key') }}</label>
                            <a id="providerApiKeyLink" href="#" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">{{ __('Get API key') }}</a>
                        </div>
                        <input id="providerApiKey" type="password" autocomplete="new-password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" placeholder="{{ __('Paste API key') }}">
                        <p id="providerApiKeyHelp" class="mt-1 hidden text-xs text-gray-500 dark:text-gray-400">{{ __('Leave blank to keep the current API key.') }}</p>
                    </div>

                    <div>
                        <label for="providerModel" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Model') }}</label>
                        <select id="providerModel" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"></select>
                    </div>

                    <div id="providerAccountIdGroup" class="hidden">
                        <label for="providerAccountId" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Cloudflare Account ID') }}</label>
                        <input id="providerAccountId" type="text" autocomplete="off" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" placeholder="{{ __('Paste Cloudflare Account ID') }}">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required with a Workers AI API token.') }}</p>
                    </div>

                    <label class="flex items-center justify-between gap-4 rounded-md border border-gray-200 px-4 py-3 dark:border-gray-700">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Enabled') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Allow the Transcriber app to use this provider.') }}</span>
                        </span>
                        <span class="relative inline-flex cursor-pointer items-center">
                            <input id="providerEnabled" type="checkbox" value="1" class="peer sr-only" checked>
                            <span class="h-5 w-10 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-green-500 peer-checked:after:translate-x-5 dark:bg-gray-600"></span>
                        </span>
                    </label>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    <button type="button" onclick="closeProviderModal()" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ __('Cancel') }}</button>
                    <button id="providerSubmitButton" type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">{{ __('Add Provider') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Provider Fallback Log Modal -->
<div id="providerLogsModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50" role="dialog" aria-modal="true" aria-labelledby="providerLogsTitle">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-4xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <div>
                    <h3 id="providerLogsTitle" class="text-lg font-semibold text-gray-900 dark:text-gray-100">Provider Log</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Latest fallback failures and successful recoveries.</p>
                </div>
                <div class="flex items-center gap-3">
                    <button id="providerLogsRefresh" type="button" class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">Refresh</button>
                    <button type="button" onclick="closeProviderLogs()" class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>
            <div id="providerLogsContent" class="max-h-[65vh] overflow-y-auto p-6">
                <p class="text-center text-sm text-gray-500 dark:text-gray-400">Loading logs…</p>
            </div>
        </div>
    </div>
</div>

<!-- Token Modal -->
<div id="tokenModal" class="fixed inset-0 hidden overflow-y-auto h-full w-full z-50">
    <div class="flex items-center justify-center min-h-full p-4">
        <div class="p-5 border w-96 shadow-lg rounded-lg bg-white dark:bg-gray-800 dark:border-gray-700">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">License Key</h3>
            <button type="button" onclick="closeTokenModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bearer license key:</label>
            <div class="flex items-center space-x-2">
                <input type="text" id="modalToken" readonly class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 font-mono text-sm" value="">
                <button type="button" onclick="copyModalToken()" class="inline-flex items-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition duration-150">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                    </svg>
                    Copy
                </button>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="button" onclick="closeTokenModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 dark:bg-gray-600 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm font-medium rounded-md transition duration-150">
                Close
            </button>
        </div>
    </div>
</div>

<script>
    const providerCatalog = @json(collect($transcriptionProviders)->keyBy('integration_key')->all());
    let providerModalMode = 'add';
    let providerLogCategory = null;

    function renderProviderLogs(logs) {
        const content = document.getElementById('providerLogsContent');
        content.replaceChildren();

        if (!logs.length) {
            const empty = document.createElement('p');
            empty.className = 'py-8 text-center text-sm text-gray-500 dark:text-gray-400';
            empty.textContent = 'No fallback errors have been recorded for this group.';
            content.appendChild(empty);
            return;
        }

        const list = document.createElement('div');
        list.className = 'divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700';

        logs.forEach(log => {
            const row = document.createElement('div');
            row.className = 'space-y-2 px-4 py-3';

            const heading = document.createElement('div');
            heading.className = 'flex flex-wrap items-center justify-between gap-2';

            const provider = document.createElement('p');
            provider.className = 'text-sm font-semibold text-gray-900 dark:text-gray-100';
            provider.textContent = `${log.provider || 'Unknown provider'} · ${log.model || 'Unknown model'}`;

            const badge = document.createElement('span');
            const recovered = log.status === 'fallback_succeeded';
            badge.className = `inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${recovered ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'}`;
            badge.textContent = recovered ? 'Fallback recovered' : 'Failed · fallback continued';
            heading.append(provider, badge);

            const meta = document.createElement('p');
            meta.className = 'text-xs text-gray-500 dark:text-gray-400';
            const http = log.http_status ? ` · HTTP ${log.http_status}` : '';
            meta.textContent = `${new Date(log.created_at).toLocaleString()} · ${log.source} · Priority ${log.fallback_position || '?'}${http}`;

            row.append(heading, meta);
            if (log.error) {
                const error = document.createElement('p');
                error.className = 'text-sm text-red-700 dark:text-red-300';
                error.textContent = log.error;
                row.appendChild(error);
            }
            list.appendChild(row);
        });

        content.appendChild(list);
    }

    async function loadProviderLogs() {
        const content = document.getElementById('providerLogsContent');
        content.innerHTML = '<p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Loading logs…</p>';

        try {
            const url = new URL('{{ route('api.transcription-providers.logs') }}', window.location.origin);
            url.searchParams.set('category', providerLogCategory);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`Log request returned ${response.status}`);
            }

            const payload = await response.json();
            renderProviderLogs(payload.logs || []);
        } catch (error) {
            content.innerHTML = '<p class="py-8 text-center text-sm text-red-600 dark:text-red-400">The provider log could not be loaded.</p>';
        }
    }

    function openProviderLogs(category, title) {
        providerLogCategory = category;
        document.getElementById('providerLogsTitle').textContent = `${title} Log`;
        document.getElementById('providerLogsModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        loadProviderLogs();
    }

    function closeProviderLogs() {
        document.getElementById('providerLogsModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    document.getElementById('providerLogsRefresh').addEventListener('click', loadProviderLogs);

    const providerHealthClasses = {
        online: ['bg-green-100', 'text-green-800', 'dark:bg-green-900/40', 'dark:text-green-300'],
        limited: ['bg-amber-100', 'text-amber-800', 'dark:bg-amber-900/40', 'dark:text-amber-300'],
        offline: ['bg-red-100', 'text-red-800', 'dark:bg-red-900/40', 'dark:text-red-300'],
        disabled: ['bg-gray-100', 'text-gray-600', 'dark:bg-gray-700', 'dark:text-gray-300']
    };

    function updateProviderHealth(settingId, health) {
        const badge = document.getElementById(`provider-health-${settingId}`);

        if (!badge) {
            return;
        }

        Object.values(providerHealthClasses).flat().forEach(className => badge.classList.remove(className));
        (providerHealthClasses[health.status] || providerHealthClasses.offline)
            .forEach(className => badge.classList.add(className));
        badge.textContent = health.label;
        badge.title = `${health.message} Checked ${new Date(health.checked_at).toLocaleString()}`;
    }

    async function loadProviderHealth() {
        const badges = document.querySelectorAll('.provider-health');

        if (!badges.length) {
            return;
        }

        try {
            const response = await fetch('{{ route('api.transcription-providers.health') }}', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Health request returned ${response.status}`);
            }

            const payload = await response.json();
            Object.entries(payload.providers || {}).forEach(([settingId, health]) => {
                updateProviderHealth(settingId, health);
            });
        } catch (error) {
            badges.forEach(badge => {
                if (badge.textContent.trim() === 'Checking…') {
                    updateProviderHealth(badge.id.replace('provider-health-', ''), {
                        status: 'offline',
                        label: 'Check failed',
                        message: 'The server could not complete this provider check.',
                        checked_at: new Date().toISOString()
                    });
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', loadProviderHealth);

    function openApiModal() {
        document.getElementById('apiModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
    
    function closeApiModal() {
        document.getElementById('apiModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
    
    function showTokenModal(apiId, token) {
        document.getElementById('modalToken').value = token;
        document.getElementById('tokenModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
    
    function closeTokenModal() {
        document.getElementById('tokenModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function providerOptions(category) {
        return Object.values(providerCatalog).filter(provider => (
            provider.category === category && !provider.configured
        ));
    }

    function renderProviderModels(integrationKey) {
        const provider = providerCatalog[integrationKey];
        const modelSelect = document.getElementById('providerModel');

        modelSelect.innerHTML = '';

        if (!provider) {
            return;
        }

        provider.models.forEach(model => {
            const option = new Option(provider.model_labels[model] || model, model, false, model === provider.model);
            modelSelect.add(option);
        });

        const apiKeyLink = document.getElementById('providerApiKeyLink');
        apiKeyLink.href = provider.api_key_url;

        const credentialLabel = provider.credential_label || 'API Key';
        const credentialPlaceholder = provider.credential_placeholder || 'Paste API key';
        const credentialHelp = provider.credential_help || 'Leave blank to keep the current API key.';
        document.getElementById('providerApiKeyLabel').textContent = credentialLabel;

        const accountIdGroup = document.getElementById('providerAccountIdGroup');
        const accountIdInput = document.getElementById('providerAccountId');
        const requiresAccountId = provider.requires_account_id === true;
        accountIdGroup.classList.toggle('hidden', !requiresAccountId);
        accountIdInput.required = requiresAccountId;
        accountIdInput.value = requiresAccountId ? (provider.metadata?.account_id || '') : '';

        if (providerModalMode === 'add') {
            const apiKeyInput = document.getElementById('providerApiKey');
            const apiKeyHelp = document.getElementById('providerApiKeyHelp');
            const canReuseApiKey = provider.has_reusable_api_key === true;

            apiKeyInput.value = '';
            apiKeyInput.placeholder = canReuseApiKey ? provider.masked_api_key : credentialPlaceholder;
            apiKeyInput.required = !canReuseApiKey;
            apiKeyHelp.textContent = canReuseApiKey
                ? `Leave blank to reuse the existing encrypted ${credentialLabel.toLowerCase()}, or enter a replacement.`
                : credentialHelp;
            apiKeyHelp.classList.toggle('hidden', !canReuseApiKey && !provider.credential_help);
        }
    }

    function openProviderModal(category) {
        const providers = providerOptions(category);

        if (!providers.length) {
            showNotification('All available providers in this group have already been added.', 'info');
            return;
        }

        providerModalMode = 'add';

        const providerSelect = document.getElementById('providerSelect');
        providerSelect.disabled = false;
        providerSelect.innerHTML = '';
        providers.forEach(provider => providerSelect.add(new Option(provider.name, provider.integration_key)));

        document.getElementById('providerModalTitle').textContent = 'Add Provider';
        document.getElementById('providerModalDescription').textContent = 'Choose an available provider and enter its API key.';
        document.getElementById('providerSubmitButton').textContent = 'Add Provider';
        document.getElementById('providerApiKey').value = '';
        document.getElementById('providerApiKey').placeholder = 'Paste API key';
        document.getElementById('providerApiKey').required = true;
        document.getElementById('providerApiKeyHelp').classList.add('hidden');
        document.getElementById('providerEnabled').checked = true;

        renderProviderModels(providerSelect.value);
        document.getElementById('providerModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function openEditProviderModal(integrationKey) {
        const provider = providerCatalog[integrationKey];

        if (!provider || !provider.configured) {
            return;
        }

        providerModalMode = 'edit';

        const providerSelect = document.getElementById('providerSelect');
        providerSelect.innerHTML = '';
        providerSelect.add(new Option(provider.name, provider.integration_key));
        providerSelect.disabled = true;

        document.getElementById('providerModalTitle').textContent = `Edit ${provider.name}`;
        document.getElementById('providerModalDescription').textContent = 'Update the connection details for this provider.';
        document.getElementById('providerSubmitButton').textContent = 'Save Changes';
        document.getElementById('providerApiKey').value = '';
        document.getElementById('providerApiKey').placeholder = provider.masked_api_key;
        document.getElementById('providerApiKey').required = false;
        document.getElementById('providerApiKeyHelp').textContent = `Leave blank to keep the current encrypted ${(provider.credential_label || 'API key').toLowerCase()}.`;
        document.getElementById('providerApiKeyHelp').classList.remove('hidden');
        document.getElementById('providerEnabled').checked = provider.is_enabled;

        renderProviderModels(integrationKey);
        document.getElementById('providerModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeProviderModal() {
        document.getElementById('providerModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
    
    function copyModalToken() {
        const token = document.getElementById('modalToken').value;
        
        navigator.clipboard.writeText(token).then(function() {
            showNotification('License key copied to clipboard', 'success');
        }).catch(function(err) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = token;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showNotification('License key copied to clipboard', 'success');
        });
    }

    $('#providerSelect').on('change', function() {
        renderProviderModels(this.value);
    });

    $('#providerForm').on('submit', function(e) {
        e.preventDefault();

        const integrationKey = $('#providerSelect').val();
        const provider = providerCatalog[integrationKey];
        const providerId = provider.provider;
        const apiKey = $('#providerApiKey').val().trim();
        const submitBtn = $('#providerSubmitButton');
        const originalBtnText = submitBtn.text();
        const data = {
            _token: '{{ csrf_token() }}',
            [`providers[${providerId}][api_key]`]: apiKey,
            [`providers[${providerId}][model]`]: $('#providerModel').val()
        };

        if ($('#providerEnabled').is(':checked')) {
            data[`providers[${providerId}][is_enabled]`] = 1;
        }

        if (provider.setting_id) {
            data[`providers[${providerId}][setting_id]`] = provider.setting_id;
        }

        if (provider.requires_account_id) {
            data[`providers[${providerId}][account_id]`] = $('#providerAccountId').val().trim();
        }

        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...');

        $.ajax({
            url: '{{ route('api.transcription-providers.update') }}',
            method: 'POST',
            data: data,
            success: function(response) {
                showNotification(
                    response.message || (providerModalMode === 'add' ? 'Provider added successfully' : 'Provider updated successfully'),
                    'success'
                );
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            },
            error: function(xhr) {
                const validationErrors = xhr.responseJSON?.errors;
                const firstError = validationErrors ? Object.values(validationErrors).flat()[0] : null;
                showNotification(firstError || xhr.responseJSON?.message || 'Failed to save provider settings', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalBtnText);
            }
        });
    });

    document.querySelectorAll('.provider-sortable').forEach(list => {
        let draggedRow = null;
        let originalOrder = [];

        const providerIds = () => Array.from(list.querySelectorAll('.provider-row'))
            .map(row => row.dataset.provider);

        const restoreOrder = order => {
            const rows = new Map(Array.from(list.querySelectorAll('.provider-row'))
                .map(row => [row.dataset.provider, row]));

            order.forEach(provider => {
                if (rows.has(provider)) {
                    list.appendChild(rows.get(provider));
                }
            });
        };

        list.addEventListener('dragstart', event => {
            const handle = event.target.closest('.provider-drag-handle');

            if (!handle) {
                event.preventDefault();
                return;
            }

            draggedRow = handle.closest('.provider-row');
            originalOrder = providerIds();
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', draggedRow.dataset.provider);
            const activeRow = draggedRow;

            requestAnimationFrame(() => {
                activeRow.classList.add('opacity-40', 'ring-2', 'ring-blue-400');
            });
        });

        list.addEventListener('dragover', event => {
            if (!draggedRow) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            const targetRow = event.target.closest('.provider-row');

            if (!targetRow || targetRow === draggedRow || targetRow.parentElement !== list) {
                return;
            }

            const targetBounds = targetRow.getBoundingClientRect();
            const insertAfter = event.clientY > targetBounds.top + (targetBounds.height / 2);
            list.insertBefore(draggedRow, insertAfter ? targetRow.nextSibling : targetRow);
        });

        list.addEventListener('drop', event => {
            if (!draggedRow) {
                return;
            }

            event.preventDefault();
            const providers = providerIds();

            if (providers.join('|') === originalOrder.join('|')) {
                return;
            }

            list.classList.add('pointer-events-none', 'opacity-70');

            $.ajax({
                url: '{{ route('api.transcription-providers.order') }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    category: list.dataset.category,
                    providers: providers
                },
                success: function(response) {
                    showNotification(response.message || 'Provider fallback order updated', 'success');
                },
                error: function(xhr) {
                    restoreOrder(originalOrder);
                    showNotification(xhr.responseJSON?.message || 'Failed to update provider fallback order', 'error');
                },
                complete: function() {
                    list.classList.remove('pointer-events-none', 'opacity-70');
                }
            });
        });

        list.addEventListener('dragend', () => {
            if (draggedRow) {
                draggedRow.classList.remove('opacity-40', 'ring-2', 'ring-blue-400');
            }

            draggedRow = null;
        });
    });

    const transcriberPackageInput = document.getElementById('transcriberPackage');
    const transcriberPackageDropzone = document.getElementById('transcriberPackageDropzone');
    const transcriberPackageIdle = document.getElementById('transcriberPackageIdle');
    const transcriberPackageProgress = document.getElementById('transcriberPackageProgress');
    const transcriberPackageProgressBar = document.getElementById('transcriberPackageProgressBar');
    const transcriberPackageProgressText = document.getElementById('transcriberPackageProgressText');
    const transcriberPackageProgressPercent = document.getElementById('transcriberPackageProgressPercent');
    const transcriberPackageProgressFilename = document.getElementById('transcriberPackageProgressFilename');
    let selectedTranscriberPackage = null;

    function setTranscriberDropzoneActive(active) {
        transcriberPackageDropzone.classList.toggle('border-blue-500', active);
        transcriberPackageDropzone.classList.toggle('bg-blue-50', active);
        transcriberPackageDropzone.classList.toggle('ring-2', active);
        transcriberPackageDropzone.classList.toggle('ring-blue-200', active);
        transcriberPackageDropzone.classList.toggle('dark:bg-gray-700', active);
    }

    function resetTranscriberUploadProgress() {
        transcriberPackageIdle.classList.remove('hidden');
        transcriberPackageProgress.classList.add('hidden');
        transcriberPackageProgressBar.style.width = '0%';
        transcriberPackageProgressBar.classList.remove('bg-green-600');
        transcriberPackageProgressBar.classList.add('bg-blue-600');
        transcriberPackageProgressPercent.textContent = '0%';
        transcriberPackageProgressText.textContent = 'Uploading package...';
        transcriberPackageDropzone.classList.remove('pointer-events-none', 'opacity-75');
    }

    function selectTranscriberPackage(file) {
        if (!file) {
            return false;
        }

        if (!file.name.toLowerCase().endsWith('.zip')) {
            showNotification('The Transcriber App Package must be a ZIP file.', 'error');
            return false;
        }

        if (file.size > 500 * 1024 * 1024) {
            showNotification('The Transcriber App Package must not exceed 500 MB.', 'error');
            return false;
        }

        selectedTranscriberPackage = file;
        const sizeInMb = (file.size / (1024 * 1024)).toFixed(1);
        $('#transcriberPackageLabel').text(`${file.name} (${sizeInMb} MB)`);

        return true;
    }

    $(transcriberPackageInput).on('change', function() {
        if (this.files.length && !selectTranscriberPackage(this.files[0])) {
            this.value = '';
        }
    });

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        transcriberPackageDropzone.addEventListener(eventName, event => {
            event.preventDefault();
            event.stopPropagation();
        });
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        transcriberPackageDropzone.addEventListener(eventName, () => setTranscriberDropzoneActive(true));
    });

    ['dragleave', 'drop'].forEach(eventName => {
        transcriberPackageDropzone.addEventListener(eventName, () => setTranscriberDropzoneActive(false));
    });

    transcriberPackageDropzone.addEventListener('drop', event => {
        const file = event.dataTransfer?.files?.[0];

        if (selectTranscriberPackage(file)) {
            transcriberPackageInput.value = '';
        }
    });

    function transcriberUploadErrorMessage(xhr) {
        const validationErrors = xhr.responseJSON?.errors;
        const validationMessage = validationErrors ? Object.values(validationErrors).flat()[0] : null;

        if (validationMessage) {
            return validationMessage;
        }

        if (xhr.responseJSON?.message) {
            return xhr.responseJSON.message;
        }

        const messages = {
            0: 'The upload connection was interrupted before the server returned a response.',
            408: 'The upload timed out before the server received the complete package.',
            413: 'The package upload returned HTTP 413 because the submitted request was considered too large.',
            419: 'Your session expired during the upload. Refresh this page and try again.',
            422: 'The server rejected the package or version. Verify that the selected file is a valid ZIP.',
            500: 'The server failed while storing or publishing the package. Check the server log for the matching upload error.',
            502: 'The gateway lost contact with the application while uploading the package.',
            503: 'The upload service is temporarily unavailable.',
            504: 'The gateway timed out while waiting for the package upload to finish.'
        };

        return messages[xhr.status]
            || `Package upload failed with HTTP ${xhr.status || 'network'}${xhr.statusText ? `: ${xhr.statusText}` : ''}.`;
    }

    $('#transcriberPackageForm').on('submit', function(e) {
        e.preventDefault();

        if (!selectedTranscriberPackage) {
            showNotification('Choose or drop a ZIP package before uploading.', 'error');
            return;
        }

        const submitBtn = $('button[form="transcriberPackageForm"]');
        const originalBtnHtml = submitBtn.html();
        const formData = new FormData();
        formData.append('_token', this.querySelector('input[name="_token"]').value);
        formData.append('version', document.getElementById('transcriberVersion').value);
        formData.append('package', selectedTranscriberPackage, selectedTranscriberPackage.name);
        const filename = selectedTranscriberPackage.name;
        let uploadSucceeded = false;

        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...');
        transcriberPackageIdle.classList.add('hidden');
        transcriberPackageProgress.classList.remove('hidden');
        transcriberPackageProgressFilename.textContent = filename;
        transcriberPackageDropzone.classList.add('pointer-events-none', 'opacity-75');

        $.ajax({
            url: '{{ route('api.transcriber-package.upload') }}',
            method: 'POST',
            global: false,
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = $.ajaxSettings.xhr();

                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', event => {
                        if (!event.lengthComputable) {
                            return;
                        }

                        const percent = Math.min(100, Math.round((event.loaded / event.total) * 100));
                        transcriberPackageProgressBar.style.width = percent + '%';
                        transcriberPackageProgressPercent.textContent = percent + '%';

                        if (percent === 100) {
                            transcriberPackageProgressText.textContent = 'Processing package...';
                        }
                    });
                }

                return xhr;
            },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                uploadSucceeded = true;
                transcriberPackageProgressText.textContent = 'Upload complete';
                transcriberPackageProgressBar.style.width = '100%';
                transcriberPackageProgressPercent.textContent = '100%';
                transcriberPackageProgressBar.classList.remove('bg-blue-600');
                transcriberPackageProgressBar.classList.add('bg-green-600');
                showNotification(response.message || 'Transcriber App Package uploaded successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            },
            error: function(xhr) {
                showNotification(transcriberUploadErrorMessage(xhr), 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).html(originalBtnHtml);

                if (!uploadSucceeded) {
                    resetTranscriberUploadProgress();
                }
            }
        });
    });
    
    // Handle HTTP method toggles
    $(document).on('change', '.http-method-toggle', function() {
        const row = $(this).closest('tr');
        const apiId = row.data('id');
        const method = $(this).data('method');
        const isEnabled = $(this).is(':checked');
        
        // Show loading state
        const originalState = !isEnabled;
        
        $.ajax({
            url: '{{ route("api.update-method", "__id__") }}'.replace('__id__', apiId),
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                method: method,
                enabled: isEnabled ? 1 : 0
            },
            success: function(response) {
                showNotification('API settings updated successfully', 'success');
            },
            error: function(xhr) {
                // Revert the toggle on error
                $(this).prop('checked', originalState);
                showNotification('Failed to update API settings', 'error');
            }
        });
    });
    
    // Handle status toggle
    $(document).on('change', '.status-toggle', function() {
        const row = $(this).closest('tr');
        const apiId = row.data('id');
        const isActive = $(this).is(':checked');
        
        // Show loading state
        const originalState = !isActive;
        
        $.ajax({
            url: '{{ route("api.update-status", "__id__") }}'.replace('__id__', apiId),
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                is_active: isActive ? 1 : 0
            },
            success: function(response) {
                showNotification('API status updated successfully', 'success');
            },
            error: function(xhr) {
                // Revert the toggle on error
                $(this).prop('checked', originalState);
                showNotification('Failed to update API status', 'error');
            }
        });
    });
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const apiModal = document.getElementById('apiModal');
        const providerModal = document.getElementById('providerModal');

        if (event.target === apiModal) {
            closeApiModal();
        }

        if (event.target === providerModal) {
            closeProviderModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeApiModal();
            closeTokenModal();
            closeProviderModal();
        }
    });
</script>

</x-layouts.app>
