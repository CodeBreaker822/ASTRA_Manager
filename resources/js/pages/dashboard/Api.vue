<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Copy,
    Eye,
    FileText,
    GripVertical,
    Plus,
    UploadCloud,
    X,
} from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type ApiKey = {
    id: number;
    app_name: string;
    app_token: string;
    can_post: boolean;
    can_get: boolean;
    can_put: boolean;
    can_patch: boolean;
    can_delete: boolean;
    is_active: boolean;
};

type ProviderCard = {
    setting_id: number | null;
    integration_key: string;
    provider: string;
    name: string;
    category: 'transcriber' | 'text_fixer';
    models: string[];
    model: string;
    model_label: string;
    model_labels: Record<string, string>;
    configured: boolean;
    is_enabled: boolean;
    masked_api_key?: string;
    has_reusable_api_key?: boolean;
    api_key_url?: string;
    credential_label?: string;
    credential_placeholder?: string;
    credential_help?: string;
    requires_account_id?: boolean;
    requires_runpod_endpoint?: boolean;
    metadata?: Record<string, string | null>;
};

type ProviderLog = {
    id: number;
    created_at: string | null;
    source: string;
    provider: string;
    model: string | null;
    status: string;
    http_status: number | null;
    fallback_position: number | null;
    error: string | null;
};

type ProviderHealth = {
    status: 'online' | 'limited' | 'offline' | 'disabled';
    label: string;
    message: string;
    checked_at: string;
};

type TranscriberPackage = {
    version: string | null;
    zipfile: string | null;
};

type HttpMethod = 'post' | 'get' | 'put' | 'patch' | 'delete';
type ProviderCategory = 'transcriber' | 'text_fixer';
type NoticeType = 'success' | 'error' | 'info';

const props = defineProps<{
    apis: ApiKey[];
    transcriptionProviders: ProviderCard[];
    transcriberPackage: TranscriberPackage;
}>();

defineOptions({
    layout: DashboardLayout,
});

const csrfToken = document
    .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
    ?.getAttribute('content');

const methodList: HttpMethod[] = ['post', 'get', 'put', 'patch', 'delete'];
const apis = ref<ApiKey[]>([...props.apis]);
const providers = ref<ProviderCard[]>([...props.transcriptionProviders]);
const transcriberPackage = reactive({ ...props.transcriberPackage });
const notice = ref<{ type: NoticeType; message: string } | null>(null);

const apiModalOpen = ref(false);
const apiSaving = ref(false);
const apiForm = reactive({
    app_name: '',
    app_token: '',
    can_post: false,
    can_get: false,
    can_put: false,
    can_patch: false,
    can_delete: false,
    blacklisted_ips: '',
    blacklisted_routes: '',
});

const tokenModalOpen = ref(false);
const visibleToken = ref('');

const providerModalOpen = ref(false);
const providerMode = ref<'add' | 'edit'>('add');
const providerCategory = ref<ProviderCategory>('transcriber');
const selectedProviderKey = ref('');
const providerSaving = ref(false);
const providerForm = reactive({
    api_key: '',
    model: '',
    is_enabled: true,
    account_id: '',
    endpoint_id: '',
    runsync_url: '',
});

const logsModalOpen = ref(false);
const logsTitle = ref('Provider Log');
const logsCategory = ref<ProviderCategory>('transcriber');
const logsLoading = ref(false);
const providerLogs = ref<ProviderLog[]>([]);
const providerHealth = ref<Record<number, ProviderHealth>>({});
const draggingProviderId = ref<number | null>(null);

const packageFile = ref<File | null>(null);
const packageUploading = ref(false);
const packageProgress = ref(0);
const packageProgressText = ref('Uploading package...');
const dropzoneActive = ref(false);

const providerGroups = computed(() => ({
    transcriber: providers.value.filter(
        (provider) => provider.category === 'transcriber',
    ),
    text_fixer: providers.value.filter(
        (provider) => provider.category === 'text_fixer',
    ),
}));

const providerSections: Array<{
    category: ProviderCategory;
    title: string;
    description: string;
}> = [
    {
        category: 'transcriber',
        title: 'Transcribers',
        description: 'Speech-to-text providers',
    },
    {
        category: 'text_fixer',
        title: 'Text Fixers',
        description: 'Transcript polishing and cleanup providers',
    },
];

const providerCatalog = computed<Record<string, ProviderCard>>(() =>
    Object.fromEntries(
        providers.value.map((provider) => [provider.integration_key, provider]),
    ),
);

const selectedProvider = computed(
    () => providerCatalog.value[selectedProviderKey.value],
);

nextTick(() => {
    void loadProviderHealth();
});

async function requestJson<T>(
    url: string,
    options: RequestInit = {},
): Promise<T> {
    const response = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            ...(options.body instanceof FormData
                ? {}
                : { 'Content-Type': 'application/json' }),
            ...options.headers,
        },
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = Object.values(
            (payload as { errors?: Record<string, string[]> }).errors ?? {},
        )
            .flat()
            .at(0);

        throw new Error(
            String(
                firstError ??
                    (payload as { message?: string }).message ??
                    'Request failed.',
            ),
        );
    }

    return payload as T;
}

function showNotice(message: string, type: NoticeType = 'success') {
    notice.value = { message, type };
}

function openApiModal() {
    Object.assign(apiForm, {
        app_name: '',
        app_token: '',
        can_post: false,
        can_get: false,
        can_put: false,
        can_patch: false,
        can_delete: false,
        blacklisted_ips: '',
        blacklisted_routes: '',
    });
    apiModalOpen.value = true;
}

async function generateLicenseKey() {
    const payload = await requestJson<{ license_key: string }>(
        '/dashboard/api/license-key',
        { method: 'POST', body: JSON.stringify({}) },
    );

    apiForm.app_token = payload.license_key;
    showNotice('Secure license key generated');
}

async function saveApi() {
    apiSaving.value = true;

    try {
        const payload = await requestJson<{ data: ApiKey; message: string }>(
            '/api/settings/store',
            {
                method: 'POST',
                body: JSON.stringify({
                    app_name: apiForm.app_name,
                    app_token: apiForm.app_token,
                    can_post: apiForm.can_post,
                    can_get: apiForm.can_get,
                    can_put: apiForm.can_put,
                    can_patch: apiForm.can_patch,
                    can_delete: apiForm.can_delete,
                    blacklisted_ips: listJson(apiForm.blacklisted_ips),
                    blacklisted_routes: listJson(apiForm.blacklisted_routes),
                }),
            },
        );

        apis.value.push(payload.data);
        apiModalOpen.value = false;
        showNotice(payload.message || 'API settings saved successfully!');
    } catch (error) {
        showNotice(exceptionMessage(error), 'error');
    } finally {
        apiSaving.value = false;
    }
}

async function updateMethod(api: ApiKey, method: HttpMethod) {
    const key = `can_${method}` as keyof ApiKey;
    const next = !api[key];
    (api as unknown as Record<string, boolean>)[key] = next;

    try {
        await requestJson(`/api/settings/update-method/${api.id}`, {
            method: 'PUT',
            body: JSON.stringify({ method, enabled: next }),
        });
        showNotice('API settings updated successfully');
    } catch (error) {
        (api as unknown as Record<string, boolean>)[key] = !next;
        showNotice(exceptionMessage(error), 'error');
    }
}

async function updateStatus(api: ApiKey) {
    const next = !api.is_active;
    api.is_active = next;

    try {
        await requestJson(`/api/settings/update-status/${api.id}`, {
            method: 'PUT',
            body: JSON.stringify({ is_active: next }),
        });
        showNotice('API status updated successfully');
    } catch (error) {
        api.is_active = !next;
        showNotice(exceptionMessage(error), 'error');
    }
}

async function deleteApi(api: ApiKey) {
    try {
        await requestJson(`/api/settings/${api.id}`, { method: 'DELETE' });
        apis.value = apis.value.filter((item) => item.id !== api.id);
        showNotice('API deleted successfully');
    } catch (error) {
        showNotice(exceptionMessage(error), 'error');
    }
}

function showTokenModal(token: string) {
    visibleToken.value = token;
    tokenModalOpen.value = true;
}

async function copyModalToken() {
    await navigator.clipboard.writeText(visibleToken.value);
    showNotice('License key copied to clipboard');
}

function availableProviders(category: ProviderCategory): ProviderCard[] {
    return providers.value.filter(
        (provider) => provider.category === category && !provider.configured,
    );
}

function configuredProviders(category: ProviderCategory): ProviderCard[] {
    return providers.value.filter(
        (provider) => provider.category === category && provider.configured,
    );
}

function openProviderModal(category: ProviderCategory) {
    const options = availableProviders(category);

    if (options.length === 0) {
        showNotice(
            'All available providers in this group have already been added.',
            'info',
        );

        return;
    }

    providerMode.value = 'add';
    providerCategory.value = category;
    selectedProviderKey.value = options[0].integration_key;
    resetProviderForm(options[0], true);
    providerModalOpen.value = true;
}

function openEditProviderModal(provider: ProviderCard) {
    providerMode.value = 'edit';
    providerCategory.value = provider.category;
    selectedProviderKey.value = provider.integration_key;
    resetProviderForm(provider, false);
    providerModalOpen.value = true;
}

function resetProviderForm(provider: ProviderCard, adding: boolean) {
    providerForm.api_key = '';
    providerForm.model = provider.model;
    providerForm.is_enabled = adding ? true : provider.is_enabled;
    providerForm.account_id = provider.metadata?.account_id ?? '';
    providerForm.endpoint_id = provider.metadata?.endpoint_id ?? '';
    providerForm.runsync_url = provider.metadata?.runsync_url ?? '';
}

function selectProvider(key: string) {
    selectedProviderKey.value = key;
    resetProviderForm(providerCatalog.value[key], providerMode.value === 'add');
}

async function saveProvider() {
    const provider = selectedProvider.value;

    if (!provider) {
        return;
    }

    providerSaving.value = true;

    try {
        const row: Record<string, string | number> = {
            api_key: providerForm.api_key,
            model: providerForm.model,
        };

        if (providerForm.is_enabled) {
            row.is_enabled = 1;
        }

        if (provider.setting_id) {
            row.setting_id = provider.setting_id;
        }

        if (provider.requires_account_id) {
            row.account_id = providerForm.account_id;
        }

        if (provider.requires_runpod_endpoint) {
            row.endpoint_id = providerForm.endpoint_id;
            row.runsync_url = providerForm.runsync_url;
        }

        const payload = await requestJson<{
            providers: ProviderCard[];
            message: string;
        }>('/dashboard/api/transcription-providers', {
            method: 'POST',
            body: JSON.stringify({
                providers: {
                    [provider.provider]: row,
                },
            }),
        });

        providers.value = payload.providers;
        providerModalOpen.value = false;
        showNotice(
            payload.message ||
                (providerMode.value === 'add'
                    ? 'Provider added successfully'
                    : 'Provider updated successfully'),
        );
        await nextTick();
        await loadProviderHealth();
    } catch (error) {
        showNotice(exceptionMessage(error), 'error');
    } finally {
        providerSaving.value = false;
    }
}

async function loadProviderHealth() {
    if (
        !providers.value.some(
            (provider) => provider.configured && provider.is_enabled,
        )
    ) {
        return;
    }

    try {
        const payload = await requestJson<{
            providers: Record<number, ProviderHealth>;
        }>('/dashboard/api/transcription-providers/health');
        providerHealth.value = payload.providers ?? {};
    } catch {
        for (const provider of providers.value) {
            if (provider.setting_id && provider.is_enabled) {
                providerHealth.value[provider.setting_id] = {
                    status: 'offline',
                    label: 'Check failed',
                    message:
                        'The server could not complete this provider check.',
                    checked_at: new Date().toISOString(),
                };
            }
        }
    }
}

async function openProviderLogs(category: ProviderCategory, title: string) {
    logsCategory.value = category;
    logsTitle.value = `${title} Log`;
    logsModalOpen.value = true;
    await loadProviderLogs();
}

async function loadProviderLogs() {
    logsLoading.value = true;

    try {
        const url = new URL(
            '/dashboard/api/transcription-providers/logs',
            window.location.origin,
        );
        url.searchParams.set('category', logsCategory.value);
        const payload = await requestJson<{ logs: ProviderLog[] }>(
            url.toString(),
        );
        providerLogs.value = payload.logs ?? [];
    } catch (error) {
        providerLogs.value = [];
        showNotice(exceptionMessage(error), 'error');
    } finally {
        logsLoading.value = false;
    }
}

function startDrag(provider: ProviderCard) {
    draggingProviderId.value = provider.setting_id;
}

async function dropProvider(category: ProviderCategory, target: ProviderCard) {
    const draggedId = draggingProviderId.value;
    draggingProviderId.value = null;

    if (!draggedId || !target.setting_id || draggedId === target.setting_id) {
        return;
    }

    const group = configuredProviders(category);
    const from = group.findIndex(
        (provider) => provider.setting_id === draggedId,
    );
    const to = group.findIndex(
        (provider) => provider.setting_id === target.setting_id,
    );

    if (from < 0 || to < 0) {
        return;
    }

    group.splice(to, 0, group.splice(from, 1)[0]);
    providers.value = [
        ...providers.value.filter(
            (provider) =>
                provider.category !== category || !provider.configured,
        ),
        ...group,
    ];

    try {
        const payload = await requestJson<{ message: string }>(
            '/dashboard/api/transcription-providers/order',
            {
                method: 'POST',
                body: JSON.stringify({
                    category,
                    providers: group.map((provider) => provider.setting_id),
                }),
            },
        );
        showNotice(payload.message || 'Provider fallback order updated');
    } catch (error) {
        showNotice(exceptionMessage(error), 'error');
    }
}

function choosePackage(file: File | null): boolean {
    if (!file) {
        return false;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
        showNotice('The Transcriber App Package must be a ZIP file.', 'error');

        return false;
    }

    if (file.size > 500 * 1024 * 1024) {
        showNotice(
            'The Transcriber App Package must not exceed 500 MB.',
            'error',
        );

        return false;
    }

    packageFile.value = file;

    return true;
}

function onPackageInput(event: Event) {
    const input = event.target as HTMLInputElement;

    if (input.files?.[0] && !choosePackage(input.files[0])) {
        input.value = '';
    }
}

function onPackageDrop(event: DragEvent) {
    dropzoneActive.value = false;
    choosePackage(event.dataTransfer?.files?.[0] ?? null);
}

async function uploadPackage() {
    if (!packageFile.value) {
        showNotice('Choose or drop a ZIP package before uploading.', 'error');

        return;
    }

    if (!transcriberPackage.version) {
        showNotice('Enter a package version before uploading.', 'error');

        return;
    }

    packageUploading.value = true;
    packageProgress.value = 0;
    packageProgressText.value = 'Uploading package...';

    try {
        const formData = new FormData();
        formData.append('version', transcriberPackage.version);
        formData.append('package', packageFile.value, packageFile.value.name);

        const payload = await uploadPackageRequest(formData);
        packageProgress.value = 100;
        packageProgressText.value = 'Upload complete';
        transcriberPackage.version = payload.version;
        transcriberPackage.zipfile = payload.zipfile;
        packageFile.value = null;
        showNotice(
            payload.message || 'Transcriber App Package uploaded successfully',
        );
    } catch (error) {
        showNotice(exceptionMessage(error), 'error');
        packageProgress.value = 0;
    } finally {
        packageUploading.value = false;
    }
}

function uploadPackageRequest(
    formData: FormData,
): Promise<{ version: string; zipfile: string; message: string }> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/dashboard/api/transcriber-package');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
        }

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }

            packageProgress.value = Math.min(
                100,
                Math.round((event.loaded / event.total) * 100),
            );

            if (packageProgress.value === 100) {
                packageProgressText.value = 'Processing package...';
            }
        });

        xhr.onload = () => {
            const payload = JSON.parse(xhr.responseText || '{}');

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(payload);
            } else {
                reject(new Error(uploadErrorMessage(xhr, payload)));
            }
        };
        xhr.onerror = () =>
            reject(
                new Error(
                    'The upload connection was interrupted before the server returned a response.',
                ),
            );
        xhr.send(formData);
    });
}

function methodEnabled(api: ApiKey, method: HttpMethod): boolean {
    return Boolean(api[`can_${method}` as keyof ApiKey]);
}

function providerHealthState(provider: ProviderCard): ProviderHealth {
    if (!provider.is_enabled) {
        return {
            status: 'disabled',
            label: 'Disabled',
            message: 'This fallback row is disabled.',
            checked_at: new Date().toISOString(),
        };
    }

    if (!provider.setting_id) {
        return {
            status: 'disabled',
            label: 'Disabled',
            message: 'Provider has not been added.',
            checked_at: new Date().toISOString(),
        };
    }

    return (
        providerHealth.value[provider.setting_id] ?? {
            status: 'limited',
            label: 'Checking...',
            message: 'Checking provider and model availability.',
            checked_at: new Date().toISOString(),
        }
    );
}

function healthClass(status: ProviderHealth['status']): string {
    return {
        online: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        limited:
            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        offline: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        disabled:
            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    }[status];
}

function listJson(value: string): string {
    return JSON.stringify(
        value
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean),
    );
}

function packageLabel(): string {
    if (!packageFile.value) {
        return 'Choose a ZIP file or drag and drop it here';
    }

    return `${packageFile.value.name} (${(packageFile.value.size / (1024 * 1024)).toFixed(1)} MB)`;
}

function uploadErrorMessage(
    xhr: XMLHttpRequest,
    payload: { message?: string; errors?: Record<string, string[]> },
): string {
    const validationMessage = Object.values(payload.errors ?? {})
        .flat()
        .at(0);

    if (validationMessage) {
        return validationMessage;
    }

    if (payload.message) {
        return payload.message;
    }

    return (
        {
            0: 'The upload connection was interrupted before the server returned a response.',
            408: 'The upload timed out before the server received the complete package.',
            413: 'The package upload returned HTTP 413 because the submitted request was considered too large.',
            419: 'Your session expired during the upload. Refresh this page and try again.',
            422: 'The server rejected the package or version. Verify that the selected file is a valid ZIP.',
            500: 'The server failed while storing or publishing the package. Check the server log for the matching upload error.',
            502: 'The gateway lost contact with the application while uploading the package.',
            503: 'The upload service is temporarily unavailable.',
            504: 'The gateway timed out while waiting for the package upload to finish.',
        }[xhr.status] ??
        `Package upload failed with HTTP ${xhr.status || 'network'}${xhr.statusText ? `: ${xhr.statusText}` : ''}.`
    );
}

function exceptionMessage(error: unknown): string {
    return error instanceof Error ? error.message : 'Request failed.';
}
</script>

<template>
    <Head title="API Management" />

    <div class="space-y-6">
        <div
            v-if="notice"
            class="rounded-lg border px-4 py-3 text-sm"
            :class="{
                'border-green-200 bg-green-50 text-green-800':
                    notice.type === 'success',
                'border-red-200 bg-red-50 text-red-800':
                    notice.type === 'error',
                'border-blue-200 bg-blue-50 text-blue-800':
                    notice.type === 'info',
            }"
        >
            {{ notice.message }}
        </div>

        <section
            class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2
                            class="text-xl font-semibold text-gray-800 dark:text-gray-200"
                        >
                            API Manager
                        </h2>
                        <p
                            class="mt-1 text-sm text-gray-600 dark:text-gray-400"
                        >
                            Manage license keys and permissions for external
                            applications
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition duration-150 ease-in-out hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none"
                        @click="openApiModal"
                    >
                        <Plus class="mr-2 size-5" />
                        Add New API
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400"
                                >
                                    App Name
                                </th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400"
                                >
                                    License Key
                                </th>
                                <th
                                    v-for="method in methodList"
                                    :key="method"
                                    class="px-4 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400"
                                >
                                    {{ method }}
                                </th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-6 py-3 text-center text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="api in apis"
                                :key="api.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div
                                        class="text-sm font-medium text-gray-900 dark:text-gray-100"
                                    >
                                        {{ api.app_name }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center space-x-2">
                                        <div
                                            class="max-w-xs truncate text-sm text-gray-500 dark:text-gray-400"
                                        >
                                            ••••••••••••••••••••••••••••••••
                                        </div>
                                        <button
                                            type="button"
                                            class="text-gray-400 transition duration-150 hover:text-gray-600 dark:hover:text-gray-300"
                                            title="Show license key"
                                            @click="
                                                showTokenModal(api.app_token)
                                            "
                                        >
                                            <Eye class="size-4" />
                                        </button>
                                    </div>
                                </td>
                                <td
                                    v-for="method in methodList"
                                    :key="method"
                                    class="px-4 py-4 text-center whitespace-nowrap"
                                >
                                    <label
                                        class="relative inline-flex cursor-pointer items-center"
                                    >
                                        <input
                                            type="checkbox"
                                            class="peer sr-only"
                                            :checked="
                                                methodEnabled(api, method)
                                            "
                                            @change="updateMethod(api, method)"
                                        />
                                        <span
                                            class="h-5 w-10 rounded-full bg-gray-200 peer-checked:bg-blue-600 after:absolute after:top-[2px] after:left-[2px] after:size-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-600"
                                        />
                                    </label>
                                </td>
                                <td
                                    class="px-4 py-4 text-center whitespace-nowrap"
                                >
                                    <label
                                        class="relative inline-flex cursor-pointer items-center"
                                    >
                                        <input
                                            type="checkbox"
                                            class="peer sr-only"
                                            :checked="api.is_active"
                                            @change="updateStatus(api)"
                                        />
                                        <span
                                            class="h-5 w-10 rounded-full bg-gray-200 peer-checked:bg-green-500 after:absolute after:top-[2px] after:left-[2px] after:size-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-600"
                                        />
                                    </label>
                                </td>
                                <td
                                    class="px-6 py-4 text-center whitespace-nowrap"
                                >
                                    <button
                                        type="button"
                                        class="text-red-600 transition duration-150 ease-in-out hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                        title="Delete"
                                        @click="deleteApi(api)"
                                    >
                                        <svg
                                            class="size-5"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                            />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="apis.length === 0">
                                <td
                                    colspan="9"
                                    class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No license keys found. Click "Add New API"
                                    to create one.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section
                v-for="section in providerSections"
                :key="section.category"
                class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <div
                    class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-700"
                >
                    <div>
                        <h2
                            class="text-lg font-semibold text-gray-800 dark:text-gray-200"
                        >
                            {{ section.title }}
                        </h2>
                        <p
                            class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"
                        >
                            {{ section.description }} · Drag to set fallback
                            priority
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="
                                openProviderLogs(
                                    section.category,
                                    section.title,
                                )
                            "
                        >
                            <FileText class="mr-1.5 size-4" />
                            Log
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-gray-600"
                            :disabled="
                                availableProviders(section.category).length ===
                                0
                            "
                            :title="
                                availableProviders(section.category).length ===
                                0
                                    ? 'All available providers have been added'
                                    : 'Add provider'
                            "
                            @click="openProviderModal(section.category)"
                        >
                            <Plus class="mr-1.5 size-4" />
                            Add
                        </button>
                    </div>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    <div
                        v-for="provider in configuredProviders(
                            section.category,
                        )"
                        :key="provider.integration_key"
                        draggable="true"
                        class="flex items-center gap-3 bg-white px-5 py-3 transition dark:bg-gray-800"
                        :class="{
                            'opacity-40 ring-2 ring-blue-400':
                                draggingProviderId === provider.setting_id,
                        }"
                        @dragstart="startDrag(provider)"
                        @dragover.prevent
                        @drop.prevent="dropProvider(section.category, provider)"
                        @dragend="draggingProviderId = null"
                    >
                        <span
                            role="button"
                            tabindex="0"
                            class="shrink-0 cursor-grab touch-none text-gray-400 select-none hover:text-gray-600 active:cursor-grabbing dark:hover:text-gray-200"
                            title="Drag to change fallback priority"
                        >
                            <GripVertical class="size-5" />
                        </span>
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                        >
                            {{ provider.name.slice(0, 1).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3
                                    class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    {{ provider.name }}
                                </h3>
                                <span
                                    class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        healthClass(
                                            providerHealthState(provider)
                                                .status,
                                        )
                                    "
                                    :title="
                                        providerHealthState(provider).message
                                    "
                                >
                                    {{ providerHealthState(provider).label }}
                                </span>
                            </div>
                            <p
                                class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ provider.model_label }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="openEditProviderModal(provider)"
                        >
                            Edit
                        </button>
                    </div>

                    <div
                        v-if="
                            configuredProviders(section.category).length === 0
                        "
                        class="px-5 py-10 text-center"
                    >
                        <p
                            class="text-sm font-medium text-gray-600 dark:text-gray-300"
                        >
                            No providers added yet
                        </p>
                        <p
                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                        >
                            Use Add to connect the first provider.
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <section
            class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="p-6">
                <div class="mb-6">
                    <h2
                        class="text-xl font-semibold text-gray-800 dark:text-gray-200"
                    >
                        Transcriber App Package
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Publish the ZIP package and version offered to licensed
                        Transcriber clients
                    </p>
                </div>

                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                    <div>
                        <label
                            for="transcriberVersion"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >
                            Version
                        </label>
                        <input
                            id="transcriberVersion"
                            v-model="transcriberPackage.version"
                            type="text"
                            required
                            maxlength="50"
                            placeholder="1.2.0"
                            class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                        />
                        <p
                            class="mt-2 text-xs text-gray-500 dark:text-gray-400"
                        >
                            Use the version reported to clients, for example
                            1.2.0.
                        </p>
                    </div>

                    <div class="lg:col-span-2">
                        <label
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >
                            Upload package
                        </label>
                        <label
                            class="mt-1 flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-5 text-center transition hover:border-blue-400 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-900 dark:hover:border-blue-500 dark:hover:bg-gray-700"
                            :class="{
                                'border-blue-500 bg-blue-50 ring-2 ring-blue-200 dark:bg-gray-700':
                                    dropzoneActive,
                                'pointer-events-none opacity-75':
                                    packageUploading,
                            }"
                            @dragenter.prevent="dropzoneActive = true"
                            @dragover.prevent="dropzoneActive = true"
                            @dragleave.prevent="dropzoneActive = false"
                            @drop.prevent="onPackageDrop"
                        >
                            <div
                                v-if="!packageUploading"
                                class="flex flex-col items-center"
                            >
                                <UploadCloud
                                    class="mb-2 size-8 text-gray-400"
                                />
                                <span
                                    class="text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ packageLabel() }}
                                </span>
                                <span
                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    ZIP only, up to 500 MB
                                </span>
                            </div>
                            <div
                                v-else
                                class="w-full max-w-md"
                                role="status"
                                aria-live="polite"
                            >
                                <div
                                    class="flex items-center justify-between text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    <span>{{ packageProgressText }}</span>
                                    <span>{{ packageProgress }}%</span>
                                </div>
                                <div
                                    class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                                >
                                    <div
                                        class="h-full rounded-full bg-blue-600 transition-all duration-200"
                                        :class="{
                                            'bg-green-600':
                                                packageProgress === 100,
                                        }"
                                        :style="{
                                            width: `${packageProgress}%`,
                                        }"
                                    />
                                </div>
                                <p
                                    class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ packageFile?.name }}
                                </p>
                            </div>
                            <input
                                type="file"
                                accept=".zip,application/zip"
                                class="sr-only"
                                @change="onPackageInput"
                            />
                        </label>
                    </div>
                </div>

                <div
                    class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700"
                >
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        <template v-if="transcriberPackage.zipfile">
                            <span
                                class="font-medium text-gray-800 dark:text-gray-200"
                                >Current package:</span
                            >
                            <span class="font-mono">
                                {{ transcriberPackage.zipfile }}</span
                            >
                            <span
                                v-if="transcriberPackage.version"
                                class="ml-2 inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300"
                            >
                                v{{ transcriberPackage.version }}
                            </span>
                        </template>
                        <template v-else>
                            No Transcriber App Package has been uploaded yet.
                        </template>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition duration-150 ease-in-out hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-70"
                        :disabled="packageUploading"
                        @click="uploadPackage"
                    >
                        <UploadCloud class="mr-2 size-5" />
                        Upload Package
                    </button>
                </div>
            </div>
        </section>
    </div>

    <div
        v-if="apiModalOpen"
        class="fixed inset-0 z-50 h-full w-full overflow-y-auto bg-gray-600/50"
        @click.self="apiModalOpen = false"
    >
        <div
            class="relative top-20 mx-auto w-11/12 rounded-md border bg-white p-5 shadow-lg md:max-w-3xl dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex items-center justify-between pb-3">
                <h3 class="text-xl font-semibold dark:text-gray-100">
                    Add New API
                </h3>
                <button
                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                    @click="apiModalOpen = false"
                >
                    <X class="size-6" />
                </button>
            </div>

            <form class="mt-4 space-y-6" @submit.prevent="saveApi">
                <div>
                    <label
                        for="app_name"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >App Name</label
                    >
                    <input
                        id="app_name"
                        v-model="apiForm.app_name"
                        type="text"
                        class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                        placeholder="Enter application name"
                        required
                    />
                </div>

                <div>
                    <label
                        for="app_token"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >License Key</label
                    >
                    <div class="flex">
                        <input
                            id="app_token"
                            v-model="apiForm.app_token"
                            type="text"
                            class="focus:ring-opacity-50 mt-1 block w-full rounded-l-md border-gray-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            placeholder="License key will be generated automatically"
                            readonly
                        />
                        <button
                            type="button"
                            class="rounded-r-md bg-gray-200 px-4 py-2 font-semibold text-gray-800 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500"
                            @click="generateLicenseKey"
                        >
                            Generate Key
                        </button>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Use this value as the Bearer license key for standalone
                        apps.
                    </p>
                </div>

                <div>
                    <h3
                        class="mb-3 text-lg font-medium text-gray-900 dark:text-gray-100"
                    >
                        HTTP Methods
                    </h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <label
                            v-for="method in methodList"
                            :key="method"
                            class="flex items-center"
                        >
                            <span
                                class="relative mr-3 inline-flex cursor-pointer items-center"
                            >
                                <input
                                    v-model="
                                        apiForm[
                                            `can_${method}` as keyof typeof apiForm
                                        ]
                                    "
                                    type="checkbox"
                                    class="peer sr-only"
                                    value="1"
                                />
                                <span
                                    class="h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 after:absolute after:top-[2px] after:left-[2px] after:size-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full"
                                />
                            </span>
                            <span
                                class="font-medium text-gray-700 uppercase dark:text-gray-300"
                                >{{ method }}</span
                            >
                        </label>
                    </div>
                </div>

                <div>
                    <label
                        for="blacklisted_ips"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Blacklisted IPs</label
                    >
                    <textarea
                        id="blacklisted_ips"
                        v-model="apiForm.blacklisted_ips"
                        rows="3"
                        class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                        placeholder="Enter IP addresses separated by commas"
                    />
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Enter IP addresses that should be blocked from accessing
                        the API (e.g., 192.168.1.1, 10.0.0.1)
                    </p>
                </div>

                <div>
                    <label
                        for="blacklisted_routes"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Blacklisted Routes</label
                    >
                    <textarea
                        id="blacklisted_routes"
                        v-model="apiForm.blacklisted_routes"
                        rows="3"
                        class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                        placeholder="Enter routes separated by commas"
                    />
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Enter routes that should be blocked from access (e.g.,
                        /api/users, /api/admin)
                    </p>
                </div>

                <div
                    class="mt-6 flex justify-end space-x-3 border-t pt-4 dark:border-gray-700"
                >
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        @click="apiModalOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-70"
                        :disabled="apiSaving"
                    >
                        {{ apiSaving ? 'Saving...' : 'Save API' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div
        v-if="providerModalOpen"
        class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/50"
        role="dialog"
        aria-modal="true"
        @click.self="providerModalOpen = false"
    >
        <div class="flex min-h-full items-center justify-center p-4">
            <div
                class="w-full max-w-lg rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
            >
                <form @submit.prevent="saveProvider">
                    <div
                        class="flex items-start justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700"
                    >
                        <div>
                            <h3
                                class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                            >
                                {{
                                    providerMode === 'add'
                                        ? 'Add Provider'
                                        : `Edit ${selectedProvider?.name}`
                                }}
                            </h3>
                            <p
                                class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                            >
                                {{
                                    providerMode === 'add'
                                        ? 'Choose an available provider and enter its API key.'
                                        : 'Update the connection details for this provider.'
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300"
                            @click="providerModalOpen = false"
                        >
                            <X class="size-6" />
                        </button>
                    </div>

                    <div class="space-y-5 px-6 py-5">
                        <div>
                            <label
                                for="providerSelect"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Provider
                            </label>
                            <select
                                id="providerSelect"
                                :value="selectedProviderKey"
                                :disabled="providerMode === 'edit'"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-80 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                @change="
                                    selectProvider(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option
                                    v-for="provider in providerMode === 'add'
                                        ? availableProviders(providerCategory)
                                        : selectedProvider
                                          ? [selectedProvider]
                                          : []"
                                    :key="provider.integration_key"
                                    :value="provider.integration_key"
                                >
                                    {{ provider.name }}
                                </option>
                            </select>
                        </div>

                        <div v-if="selectedProvider">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <label
                                    for="providerApiKey"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    {{
                                        selectedProvider.credential_label ??
                                        'API Key'
                                    }}
                                </label>
                                <a
                                    v-if="selectedProvider.api_key_url"
                                    :href="selectedProvider.api_key_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    Get API key
                                </a>
                            </div>
                            <input
                                id="providerApiKey"
                                v-model="providerForm.api_key"
                                type="password"
                                autocomplete="new-password"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                :placeholder="
                                    providerMode === 'edit'
                                        ? selectedProvider.masked_api_key
                                        : selectedProvider.has_reusable_api_key
                                          ? selectedProvider.masked_api_key
                                          : (selectedProvider.credential_placeholder ??
                                            'Paste API key')
                                "
                                :required="
                                    providerMode === 'add' &&
                                    !selectedProvider.has_reusable_api_key
                                "
                            />
                            <p
                                v-if="
                                    providerMode === 'edit' ||
                                    selectedProvider.has_reusable_api_key ||
                                    selectedProvider.credential_help
                                "
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{
                                    providerMode === 'edit'
                                        ? `Leave blank to keep the current encrypted ${(selectedProvider.credential_label ?? 'API key').toLowerCase()}.`
                                        : selectedProvider.has_reusable_api_key
                                          ? `Leave blank to reuse the existing encrypted ${(selectedProvider.credential_label ?? 'API key').toLowerCase()}, or enter a replacement.`
                                          : selectedProvider.credential_help
                                }}
                            </p>
                        </div>

                        <div v-if="selectedProvider">
                            <label
                                for="providerModel"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Model
                            </label>
                            <select
                                id="providerModel"
                                v-model="providerForm.model"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            >
                                <option
                                    v-for="model in selectedProvider.models"
                                    :key="model"
                                    :value="model"
                                >
                                    {{
                                        selectedProvider.model_labels[model] ??
                                        model
                                    }}
                                </option>
                            </select>
                        </div>

                        <div v-if="selectedProvider?.requires_account_id">
                            <label
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Cloudflare Account ID
                            </label>
                            <input
                                v-model="providerForm.account_id"
                                type="text"
                                autocomplete="off"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                placeholder="Paste Cloudflare Account ID"
                                required
                            />
                            <p
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                Required with a Workers AI API token.
                            </p>
                        </div>

                        <div
                            v-if="selectedProvider?.requires_runpod_endpoint"
                            class="space-y-3"
                        >
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >RunPod Endpoint ID</label
                                >
                                <input
                                    v-model="providerForm.endpoint_id"
                                    type="text"
                                    autocomplete="off"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                    placeholder="Example: abc123xyz"
                                />
                            </div>
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >RunPod Runsync URL</label
                                >
                                <input
                                    v-model="providerForm.runsync_url"
                                    type="url"
                                    autocomplete="off"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                    placeholder="https://api.runpod.ai/v2/{endpoint_id}/runsync"
                                />
                                <p
                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    Use either Endpoint ID or the full /runsync
                                    URL from the RunPod serverless endpoint.
                                </p>
                            </div>
                        </div>

                        <label
                            class="flex items-center justify-between gap-4 rounded-md border border-gray-200 px-4 py-3 dark:border-gray-700"
                        >
                            <span>
                                <span
                                    class="block text-sm font-medium text-gray-800 dark:text-gray-200"
                                    >Enabled</span
                                >
                                <span
                                    class="block text-xs text-gray-500 dark:text-gray-400"
                                    >Allow the Transcriber app to use this
                                    provider.</span
                                >
                            </span>
                            <span
                                class="relative inline-flex cursor-pointer items-center"
                            >
                                <input
                                    v-model="providerForm.is_enabled"
                                    type="checkbox"
                                    value="1"
                                    class="peer sr-only"
                                />
                                <span
                                    class="h-5 w-10 rounded-full bg-gray-200 peer-checked:bg-green-500 after:absolute after:top-[2px] after:left-[2px] after:size-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-600"
                                />
                            </span>
                        </label>
                    </div>

                    <div
                        class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700"
                    >
                        <button
                            type="button"
                            class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                            @click="providerModalOpen = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-70"
                            :disabled="providerSaving"
                        >
                            {{
                                providerSaving
                                    ? 'Saving...'
                                    : providerMode === 'add'
                                      ? 'Add Provider'
                                      : 'Save Changes'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div
        v-if="logsModalOpen"
        class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/50"
        role="dialog"
        aria-modal="true"
        @click.self="logsModalOpen = false"
    >
        <div class="flex min-h-full items-center justify-center p-4">
            <div
                class="w-full max-w-4xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
            >
                <div
                    class="flex items-start justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700"
                >
                    <div>
                        <h3
                            class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                        >
                            {{ logsTitle }}
                        </h3>
                        <p
                            class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                        >
                            Latest fallback failures and successful recoveries.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            @click="loadProviderLogs"
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300"
                            @click="logsModalOpen = false"
                        >
                            <X class="size-6" />
                        </button>
                    </div>
                </div>
                <div class="max-h-[65vh] overflow-y-auto p-6">
                    <p
                        v-if="logsLoading"
                        class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                    >
                        Loading logs...
                    </p>
                    <p
                        v-else-if="providerLogs.length === 0"
                        class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                    >
                        No fallback errors have been recorded for this group.
                    </p>
                    <div
                        v-else
                        class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700"
                    >
                        <div
                            v-for="log in providerLogs"
                            :key="log.id"
                            class="space-y-2 px-4 py-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p
                                    class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    {{ log.provider || 'Unknown provider' }} ·
                                    {{ log.model || 'Unknown model' }}
                                </p>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        log.status === 'fallback_succeeded'
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                                            : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
                                    "
                                >
                                    {{
                                        log.status === 'fallback_succeeded'
                                            ? 'Fallback recovered'
                                            : 'Failed · fallback continued'
                                    }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{
                                    log.created_at
                                        ? new Date(
                                              log.created_at,
                                          ).toLocaleString()
                                        : 'Unknown time'
                                }}
                                · {{ log.source }} · Priority
                                {{ log.fallback_position || '?' }}
                                <span v-if="log.http_status">
                                    · HTTP {{ log.http_status }}</span
                                >
                            </p>
                            <p
                                v-if="log.error"
                                class="text-sm text-red-700 dark:text-red-300"
                            >
                                {{ log.error }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        v-if="tokenModalOpen"
        class="fixed inset-0 z-50 h-full w-full overflow-y-auto bg-gray-900/50"
        @click.self="tokenModalOpen = false"
    >
        <div class="flex min-h-full items-center justify-center p-4">
            <div
                class="w-96 rounded-lg border bg-white p-5 shadow-lg dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3
                        class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                    >
                        License Key
                    </h3>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        @click="tokenModalOpen = false"
                    >
                        <X class="size-6" />
                    </button>
                </div>
                <div class="mb-4">
                    <label
                        class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Bearer license key:</label
                    >
                    <div class="flex items-center space-x-2">
                        <input
                            :value="visibleToken"
                            readonly
                            class="flex-1 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition duration-150 hover:bg-blue-700"
                            @click="copyModalToken"
                        >
                            <Copy class="mr-1 size-4" />
                            Copy
                        </button>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-gray-300 px-4 py-2 text-sm font-medium text-gray-800 transition duration-150 hover:bg-gray-400 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="tokenModalOpen = false"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
