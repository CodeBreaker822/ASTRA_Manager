<x-layouts.dashboard title="API Management" :entries="['resources/js/dashboard-api.js']">
    <div class="space-y-6">
        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">API Manager</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Manage license keys and permissions for external applications
                        </p>
                    </div>
                    <button type="button" data-open-modal="#api-modal"
                            class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none">
                        <x-icon name="plus" class="mr-2 size-5" />
                        Add New API
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase">App Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase">License Key</th>
                                @foreach ($httpMethods as $method)
                                    <th class="px-4 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase">{{ $method }}</th>
                                @endforeach
                                <th class="px-4 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse ($apis as $api)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ $api['app_name'] }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-2">
                                            <div class="max-w-xs truncate font-mono text-sm text-gray-500">{{ $api['masked_token'] }}</div>
                                            <span class="font-mono text-xs text-gray-400"
                                                  title="{{ $api['token_suffix'] ? 'Ends with '.$api['token_suffix'] : 'Token is hidden' }}">
                                                {{ $api['token_suffix'] ?: 'hidden' }}
                                            </span>
                                        </div>
                                    </td>
                                    @foreach ($httpMethods as $method)
                                        <td class="px-4 py-4 text-center whitespace-nowrap">
                                            <label class="relative inline-flex cursor-pointer items-center">
                                                <input type="checkbox" class="peer sr-only"
                                                       data-api-method="{{ $method }}" data-api-id="{{ $api['id'] }}"
                                                       @checked($api['can_'.$method])>
                                                <span class="{{ config('ui.dashboard.toggle_track') }} peer-checked:bg-blue-600"></span>
                                            </label>
                                        </td>
                                    @endforeach
                                    <td class="px-4 py-4 text-center whitespace-nowrap">
                                        <label class="relative inline-flex cursor-pointer items-center">
                                            <input type="checkbox" class="peer sr-only"
                                                   data-api-status data-api-id="{{ $api['id'] }}"
                                                   @checked($api['is_active'])>
                                            <span class="{{ config('ui.dashboard.toggle_track') }} peer-checked:bg-green-500"></span>
                                        </label>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <button type="button" title="Delete" data-api-delete="{{ $api['id'] }}"
                                                class="text-red-600 transition hover:text-red-900">
                                            <x-icon name="trash-2" class="size-5" />
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">
                                        No license keys found. Click "Add New API" to create one.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            @foreach ($providerSections as $section)

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">{{ $section['title'] }}</h2>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ $section['description'] }} · Drag to set fallback priority
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" data-open-logs="{{ $section['category'] }}" data-logs-title="{{ $section['title'] }}"
                                    class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                <x-icon name="file-text" class="mr-1.5 size-4" />
                                Log
                            </button>
                            <button type="button" data-add-provider="{{ $section['category'] }}"
                                    @disabled($section['available'] === [])
                                    title="{{ $section['available'] === [] ? 'All available providers have been added' : 'Add provider' }}"
                                    class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                                <x-icon name="plus" class="mr-1.5 size-4" />
                                Add
                            </button>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-200" data-provider-list="{{ $section['category'] }}">
                        @forelse ($section['configured'] as $provider)
                            <div draggable="true"
                                 data-provider-row
                                 data-setting-id="{{ $provider['setting_id'] }}"
                                 data-integration-key="{{ $provider['integration_key'] }}"
                                 data-provider-name="{{ $provider['name'] }}"
                                 class="flex items-center gap-3 bg-white px-5 py-3 transition">
                                <span role="button" tabindex="0" title="Drag to change fallback priority"
                                      class="shrink-0 cursor-grab touch-none text-gray-400 select-none hover:text-gray-600 active:cursor-grabbing">
                                    <x-icon name="grip-vertical" class="size-5" />
                                </span>
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-sm font-bold text-blue-700">
                                    {{ mb_strtoupper(mb_substr($provider['name'], 0, 1)) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="truncate text-sm font-semibold text-gray-900">{{ $provider['name'] }}</h3>
                                        @if ($provider['is_enabled'])
                                            <span data-provider-health="{{ $provider['setting_id'] }}" data-status="limited"
                                                  title="Checking provider and model availability."
                                                  class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium
                                                         data-[status=online]:bg-green-100 data-[status=online]:text-green-800
                                                         data-[status=limited]:bg-amber-100 data-[status=limited]:text-amber-800
                                                         data-[status=offline]:bg-red-100 data-[status=offline]:text-red-800">
                                                Checking...
                                            </span>
                                        @else
                                            <span title="This fallback row is disabled."
                                                  class="inline-flex shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">
                                                Disabled
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-gray-500">{{ $provider['model_label'] }}</p>
                                </div>
                                <button type="button" data-edit-provider
                                        class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                                    Edit
                                </button>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center">
                                <p class="text-sm font-medium text-gray-600">No providers added yet</p>
                                <p class="mt-1 text-xs text-gray-500">Use Add to connect the first provider.</p>
                            </div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="p-6">
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-800">Transcriber App Package</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Publish the ZIP package and version offered to licensed Transcriber clients
                    </p>
                </div>

                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                    <div>
                        <label for="transcriberVersion" class="block text-sm font-medium text-gray-700">Version</label>
                        <input id="transcriberVersion" type="text" required maxlength="50" placeholder="1.2.0"
                               value="{{ $transcriberPackage['version'] }}" class="{{ config('ui.dashboard.input') }}">
                        <p class="mt-2 text-xs text-gray-500">
                            Use the version reported to clients, for example 1.2.0.
                        </p>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Upload package</label>
                        <label id="package-dropzone"
                               class="mt-1 flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-5 text-center transition hover:border-blue-400 hover:bg-blue-50">
                            <div id="package-idle" class="flex flex-col items-center">
                                <x-icon name="upload-cloud" class="mb-2 size-8 text-gray-400" />
                                <span id="package-label" class="text-sm font-medium text-gray-700">
                                    Choose a ZIP file or drag and drop it here
                                </span>
                                <span class="mt-1 text-xs text-gray-500">ZIP only, up to 500 MB</span>
                            </div>
                            <div id="package-progress" class="hidden w-full max-w-md" role="status" aria-live="polite">
                                <div class="flex items-center justify-between text-sm font-medium text-gray-700">
                                    <span id="package-progress-text">Uploading package...</span>
                                    <span id="package-progress-percent">0%</span>
                                </div>
                                <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div id="package-progress-bar" class="h-full rounded-full bg-blue-600 transition-all duration-200" style="width: 0%"></div>
                                </div>
                            </div>
                            <input id="package-input" type="file" accept=".zip,application/zip" class="sr-only">
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-sm text-gray-600">
                        @if ($transcriberPackage['zipfile'])
                            <span class="font-medium text-gray-800">Current package:</span>
                            <span class="font-mono">{{ $transcriberPackage['zipfile'] }}</span>
                            @if ($transcriberPackage['version'])
                                <span class="ml-2 inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                                    v{{ $transcriberPackage['version'] }}
                                </span>
                            @endif
                        @else
                            No Transcriber App Package has been uploaded yet.
                        @endif
                    </div>
                    <button type="button" id="upload-package"
                            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70">
                        <x-icon name="upload-cloud" class="mr-2 size-5" />
                        Upload Package
                    </button>
                </div>
            </div>
        </section>
    </div>

    <div id="api-modal" data-modal class="hidden fixed inset-0 z-50 h-full w-full overflow-y-auto bg-gray-600/50">
        <div class="relative top-20 mx-auto w-11/12 rounded-md border bg-white p-5 shadow-lg md:max-w-3xl">
            <div class="flex items-center justify-between pb-3">
                <h3 class="text-xl font-semibold">Add New API</h3>
                <button type="button" data-close-modal="#api-modal" class="text-gray-400 hover:text-gray-500">
                    <x-icon name="x" class="size-6" />
                </button>
            </div>

            <form id="api-form" class="mt-4 space-y-6">
                <div>
                    <label for="app_name" class="block text-sm font-medium text-gray-700">App Name</label>
                    <input id="app_name" type="text" required placeholder="Enter application name" class="{{ config('ui.dashboard.input') }}">
                </div>

                <div>
                    <label for="app_token" class="block text-sm font-medium text-gray-700">License Key</label>
                    <div class="flex">
                        <input id="app_token" type="text" readonly placeholder="License key will be generated automatically"
                               class="mt-1 block w-full rounded-l-md border-gray-300 font-mono text-sm shadow-sm">
                        <button type="button" id="generate-license-key"
                                class="rounded-r-md bg-gray-200 px-4 py-2 font-semibold text-gray-800 hover:bg-gray-300">
                            Generate Key
                        </button>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">
                        Use this value as the Bearer license key for standalone apps.
                    </p>
                </div>

                <div>
                    <h3 class="mb-3 text-lg font-medium text-gray-900">HTTP Methods</h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        @foreach ($httpMethods as $method)
                            <label class="flex items-center">
                                <span class="relative mr-3 inline-flex cursor-pointer items-center">
                                    <input id="can_{{ $method }}" type="checkbox" class="peer sr-only">
                                    <span class="h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 after:absolute after:top-[2px] after:left-[2px] after:size-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full"></span>
                                </span>
                                <span class="font-medium text-gray-700 uppercase">{{ $method }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="blacklisted_ips" class="block text-sm font-medium text-gray-700">Blacklisted IPs</label>
                    <textarea id="blacklisted_ips" rows="3" placeholder="Enter IP addresses separated by commas" class="{{ config('ui.dashboard.input') }}"></textarea>
                    <p class="mt-1 text-sm text-gray-500">
                        Enter IP addresses that should be blocked from accessing the API (e.g., 192.168.1.1, 10.0.0.1)
                    </p>
                </div>

                <div>
                    <label for="blacklisted_routes" class="block text-sm font-medium text-gray-700">Blacklisted Routes</label>
                    <textarea id="blacklisted_routes" rows="3" placeholder="Enter routes separated by commas" class="{{ config('ui.dashboard.input') }}"></textarea>
                    <p class="mt-1 text-sm text-gray-500">
                        Enter routes that should be blocked from access (e.g., /api/users, /api/admin)
                    </p>
                </div>

                <div class="mt-6 flex justify-end space-x-3 border-t pt-4">
                    <button type="button" data-close-modal="#api-modal"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-70">
                        Save API
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="provider-modal" data-modal class="hidden fixed inset-0 z-50 overflow-y-auto bg-gray-900/50" role="dialog" aria-modal="true">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-lg border border-gray-200 bg-white shadow-xl">
                <form id="provider-form">
                    <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                        <div>
                            <h3 id="provider-modal-title" class="text-lg font-semibold text-gray-900">Add Provider</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Choose a provider and enter its API key.
                            </p>
                        </div>
                        <button type="button" data-close-modal="#provider-modal" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="size-6" />
                        </button>
                    </div>

                    <div class="space-y-5 px-6 py-5">
                        <div>
                            <label for="providerSelect" class="block text-sm font-medium text-gray-700">Provider</label>
                            <select id="providerSelect" required class="{{ config('ui.dashboard.input') }}">
                                @foreach ($transcriptionProviders as $provider)
                                    <option value="{{ $provider['integration_key'] }}"
                                            data-provider="{{ $provider['provider'] }}"
                                            data-category="{{ $provider['category'] }}"
                                            data-setting-id="{{ $provider['setting_id'] }}"
                                            data-reusable-key="{{ $provider['has_reusable_api_key'] ?? false ? '1' : '' }}"
                                            data-requires-account-id="{{ $provider['requires_account_id'] ?? false ? '1' : '' }}"
                                            data-requires-runpod="{{ $provider['requires_runpod_endpoint'] ?? false ? '1' : '' }}"
                                            data-discovery="{{ $provider['supports_discovery'] ? '1' : '' }}"
                                            data-configured="{{ $provider['configured'] ? '1' : '' }}"
                                            data-metadata="{{ json_encode($provider['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}">
                                        {{ $provider['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <label for="providerApiKey" class="block text-sm font-medium text-gray-700">API Key</label>
                            </div>
                            <input id="providerApiKey" type="password" autocomplete="new-password"
                                   placeholder="Paste API key" class="{{ config('ui.dashboard.input') }}">
                            <p class="mt-1 text-xs text-gray-500">
                                Leave blank when editing to keep the current encrypted key.
                            </p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <label for="providerModel" class="block text-sm font-medium text-gray-700">Model</label>
                                <button type="button" id="load-provider-models"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-800 disabled:opacity-50">
                                    <x-icon name="refresh-cw" class="size-3.5" />
                                    Load all models
                                </button>
                            </div>
                            <select id="providerModel" required class="{{ config('ui.dashboard.input') }}"></select>
                            <p id="provider-models-message" class="mt-1 text-xs text-gray-500"></p>

                            <select id="providerModelSource" hidden disabled aria-hidden="true" tabindex="-1">
                                @foreach ($transcriptionProviders as $provider)
                                    @foreach ($provider['models'] as $model)
                                        <option value="{{ $model }}"
                                                data-for="{{ $provider['integration_key'] }}"
                                                data-default="{{ $model === $provider['model'] ? '1' : '' }}">
                                            {{ $provider['model_labels'][$model] ?? $model }}
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="providerAccountId" class="block text-sm font-medium text-gray-700">Cloudflare Account ID</label>
                            <input id="providerAccountId" type="text" autocomplete="off"
                                   placeholder="Paste Cloudflare Account ID" class="{{ config('ui.dashboard.input') }}">
                            <p class="mt-1 text-xs text-gray-500">Required with a Workers AI API token.</p>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label for="providerEndpointId" class="block text-sm font-medium text-gray-700">RunPod Endpoint ID</label>
                                <input id="providerEndpointId" type="text" autocomplete="off" placeholder="Example: abc123xyz" class="{{ config('ui.dashboard.input') }}">
                            </div>
                            <div>
                                <label for="providerRunsyncUrl" class="block text-sm font-medium text-gray-700">RunPod Runsync URL</label>
                                <input id="providerRunsyncUrl" type="url" autocomplete="off"
                                       placeholder="https://api.runpod.ai/v2/{endpoint_id}/runsync" class="{{ config('ui.dashboard.input') }}">
                                <p class="mt-1 text-xs text-gray-500">
                                    Use either Endpoint ID or the full /runsync URL from the RunPod serverless endpoint.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label for="providerMetadata" class="block text-sm font-medium text-gray-700">Runtime configuration JSON</label>
                            <textarea id="providerMetadata" rows="8" spellcheck="false"
                                      class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Store provider runtime values here, such as endpoint URLs, timeout, retry, polling, and language settings.
                            </p>
                        </div>

                        <label class="flex items-center justify-between gap-4 rounded-md border border-gray-200 px-4 py-3">
                            <span>
                                <span class="block text-sm font-medium text-gray-800">Enabled</span>
                                <span class="block text-xs text-gray-500">Allow the Transcriber app to use this provider.</span>
                            </span>
                            <span class="relative inline-flex cursor-pointer items-center">
                                <input id="providerEnabled" type="checkbox" class="peer sr-only" checked>
                                <span class="{{ config('ui.dashboard.toggle_track') }} peer-checked:bg-blue-600"></span>
                            </span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                        <button type="button" data-close-modal="#provider-modal"
                                class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-70">
                            Save Provider
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="logs-modal" data-modal class="hidden fixed inset-0 z-50 overflow-y-auto bg-gray-900/50" role="dialog" aria-modal="true">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-7xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl">
                <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 id="logs-title" class="text-lg font-semibold text-gray-900">Provider Log</h3>
                        <p class="mt-1 text-sm text-gray-500">Successful provider usage and fallback attempts.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" id="refresh-logs" class="text-sm font-medium text-blue-600 hover:text-blue-800">Refresh</button>
                        <button type="button" data-close-modal="#logs-modal" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="size-6" />
                        </button>
                    </div>
                </div>
                <div id="logs-body" class="p-6"></div>
            </div>
        </div>
    </div>

    <div id="token-modal" data-modal class="hidden fixed inset-0 z-50 h-full w-full overflow-y-auto bg-gray-900/50">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="w-96 rounded-lg border bg-white p-5 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">License Key</h3>
                    <button type="button" id="close-token-modal" class="text-gray-400 hover:text-gray-600">
                        <x-icon name="x" class="size-6" />
                    </button>
                </div>
                <div class="mb-4">
                    <label for="token-value" class="mb-2 block text-sm font-medium text-gray-700">Bearer license key:</label>
                    <div class="flex items-center space-x-2">
                        <input id="token-value" readonly
                               class="flex-1 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900">
                        <button type="button" id="copy-token"
                                class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <x-icon name="copy" class="mr-1 size-4" />
                            Copy
                        </button>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="button" id="close-token-modal-footer"
                            class="rounded-md bg-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-400">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.dashboard>
