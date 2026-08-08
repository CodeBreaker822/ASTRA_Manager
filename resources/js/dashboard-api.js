/**
 * API Management dashboard.
 *
 * Blade renders every row and modal. This file only does two things:
 * talks to the JSON endpoints, and reloads the page so blade re-renders.
 */
const UPLOAD_CHUNK_BYTES = 20 * 1024 * 1024;

const UPLOAD_ERRORS = {
    0: 'The upload connection was interrupted before the server returned a response.',
    408: 'The upload timed out before the server received the complete package.',
    413: 'The package upload returned HTTP 413 because the submitted request was considered too large.',
    419: 'Your session expired during the upload. Refresh this page and try again.',
    422: 'The server rejected the package or version. Verify that the selected file is a valid ZIP.',
    500: 'The package could not be published. Please try again later.',
    502: 'The gateway lost contact with the application while uploading the package.',
    503: 'The upload service is temporarily unavailable.',
    504: 'The gateway timed out while waiting for the package upload to finish.',
};

const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

const notify = (message, type = 'success') =>
    window.showNotification(message, type);

const errorFrom = (xhr) => {
    const payload = xhr.responseJSON ?? {};

    return (
        Object.values(payload.errors ?? {}).flat()[0] ??
        payload.message ??
        UPLOAD_ERRORS[xhr.status] ??
        'Request failed.'
    );
};

/** Wraps $.ajax with the JSON + CSRF headers every endpoint here expects. */
function request(url, method = 'GET', data = null) {
    return $.ajax({
        url,
        method,
        dataType: 'json',
        contentType: data === null ? false : 'application/json',
        data: data === null ? undefined : JSON.stringify(data),
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
    });
}

/** Reloads so blade re-renders the list from the database. */
const reloadWith = (message) => {
    if (message) {
        sessionStorage.setItem('jerva.flash', message);
    }

    window.location.reload();
};

/**
 * Blade renders every provider as an option. Adding shows only the providers
 * of the clicked group that are not connected yet; editing pins the select to
 * the single row being edited. Hidden options are disabled too, so they cannot
 * be reached with the keyboard.
 */
function showProviderOptions(keep) {
    const $options = $('#providerSelect option');

    $options.each(function () {
        const visible = keep($(this));

        $(this).prop('hidden', !visible).prop('disabled', !visible);
    });

    return $options.filter((index, option) => !option.hidden);
}

/** Copies the selected provider's models out of the blade-rendered source. */
function showModelsFor(integrationKey) {
    const $source = $('#providerModelSource').find(
        `option[data-for="${integrationKey}"]`,
    );

    if ($source.length === 0) {
        return;
    }

    $('#providerModel').empty().append($source.clone());

    const preferred = $source.filter('[data-default="1"]').first().val();

    $('#providerModel').val(preferred ?? $source.first().val());
    $('#provider-models-message').text('');
}

$(function () {
    const flash = sessionStorage.getItem('jerva.flash');

    if (flash) {
        sessionStorage.removeItem('jerva.flash');
        notify(flash);
    }

    // --- API keys ---------------------------------------------------------

    $('#generate-license-key').on('click', function () {
        request('/dashboard/api/license-key', 'POST', {})
            .done((payload) => {
                $('#app_token').val(payload.license_key);
                notify('Secure license key generated');
            })
            .fail((xhr) => notify(errorFrom(xhr), 'error'));
    });

    $('#api-form').on('submit', function (event) {
        event.preventDefault();

        const $form = $(this);
        const listJson = (value) =>
            JSON.stringify(
                String(value ?? '')
                    .split(',')
                    .map((item) => item.trim())
                    .filter(Boolean),
            );

        $form.find('[type=submit]').prop('disabled', true);

        request('/api/settings/store', 'POST', {
            app_name: $('#app_name').val(),
            app_token: $('#app_token').val(),
            can_post: $('#can_post').is(':checked'),
            can_get: $('#can_get').is(':checked'),
            can_put: $('#can_put').is(':checked'),
            can_patch: $('#can_patch').is(':checked'),
            can_delete: $('#can_delete').is(':checked'),
            blacklisted_ips: listJson($('#blacklisted_ips').val()),
            blacklisted_routes: listJson($('#blacklisted_routes').val()),
        })
            .done((payload) => {
                // Show the key once before reloading; it is never shown again.
                $('#token-value').val(payload.plain_token);
                $('#api-modal').addClass('hidden');
                $('#token-modal').removeClass('hidden');
                sessionStorage.setItem(
                    'jerva.flash',
                    payload.message || 'API settings saved successfully!',
                );
            })
            .fail((xhr) => {
                notify(errorFrom(xhr), 'error');
                $form.find('[type=submit]').prop('disabled', false);
            });
    });

    $('[data-api-method]').on('change', function () {
        const $toggle = $(this);
        const enabled = $toggle.is(':checked');

        request(
            `/api/settings/update-method/${$toggle.data('api-id')}`,
            'PUT',
            {
                method: $toggle.data('api-method'),
                enabled,
            },
        )
            .done(() => notify('API settings updated successfully'))
            .fail((xhr) => {
                $toggle.prop('checked', !enabled);
                notify(errorFrom(xhr), 'error');
            });
    });

    $('[data-api-status]').on('change', function () {
        const $toggle = $(this);
        const enabled = $toggle.is(':checked');

        request(
            `/api/settings/update-status/${$toggle.data('api-id')}`,
            'PUT',
            { is_active: enabled },
        )
            .done(() => notify('API status updated successfully'))
            .fail((xhr) => {
                $toggle.prop('checked', !enabled);
                notify(errorFrom(xhr), 'error');
            });
    });

    $('[data-api-delete]').on('click', function () {
        if (!window.confirm('Delete this license key?')) {
            return;
        }

        request(`/api/settings/${$(this).data('api-delete')}`, 'DELETE')
            .done(() => reloadWith('API deleted successfully'))
            .fail((xhr) => notify(errorFrom(xhr), 'error'));
    });

    $('#close-token-modal, #close-token-modal-footer').on('click', () =>
        reloadWith(null),
    );

    // Toggle the provider fields that only some providers need, and swap in the
    // model list belonging to the newly selected provider.
    $('#providerSelect')
        .on('change', function () {
            const $selected = $(this).find(':selected');

            $('#providerAccountId')
                .closest('div')
                .toggle(Boolean($selected.data('requires-account-id')));
            $('#providerEndpointId')
                .closest('.space-y-3')
                .toggle(Boolean($selected.data('requires-runpod')));
            $('#load-provider-models').toggle(
                Boolean($selected.data('discovery')),
            );

            showModelsFor($selected.val());

            // .data() has already parsed the JSON, so re-encode it for display.
            const metadata = $selected.data('metadata');

            $('#providerMetadata').val(
                metadata ? JSON.stringify(metadata, null, 4) : '',
            );
        })
        .trigger('change');

    $('#copy-token').on('click', function () {
        navigator.clipboard.writeText($('#token-value').val());
        notify('License key copied to clipboard');
    });

    // --- Providers --------------------------------------------------------

    $('#load-provider-models').on('click', function () {
        const $button = $(this);
        const provider = $('#providerSelect')
            .find(':selected')
            .data('provider');
        const apiKey = String($('#providerApiKey').val() ?? '').trim();

        if (
            !apiKey &&
            !$('#providerSelect').find(':selected').data('reusable-key')
        ) {
            $('#provider-models-message').text(
                'Paste the API key before loading models.',
            );

            return;
        }

        $button.prop('disabled', true);
        $('#provider-models-message').text('Loading every compatible model…');

        request('/dashboard/api/transcription-providers/models', 'POST', {
            provider,
            api_key: apiKey,
        })
            .done((payload) => {
                const $select = $('#providerModel')
                    .empty()
                    .prop('disabled', false);

                payload.models.forEach((model) => {
                    $select.append(
                        $('<option>')
                            .val(model)
                            .text(payload.model_labels[model] ?? model),
                    );
                });

                $('#provider-models-message').text(
                    `${payload.models.length} compatible models loaded.`,
                );
            })
            .fail((xhr) => {
                $('#provider-models-message').text(errorFrom(xhr));
                notify(errorFrom(xhr), 'error');
            })
            .always(() => $button.prop('disabled', false));
    });

    $('#provider-form').on('submit', function (event) {
        event.preventDefault();

        const $selected = $('#providerSelect').find(':selected');
        const row = {
            api_key: $('#providerApiKey').val(),
            model: $('#providerModel').val(),
            metadata_json: $('#providerMetadata').val(),
        };

        if ($('#providerEnabled').is(':checked')) {
            row.is_enabled = 1;
        }

        if ($selected.data('setting-id')) {
            row.setting_id = $selected.data('setting-id');
        }

        if ($selected.data('requires-account-id')) {
            row.account_id = $('#providerAccountId').val();
        }

        if ($selected.data('requires-runpod')) {
            row.endpoint_id = $('#providerEndpointId').val();
            row.runsync_url = $('#providerRunsyncUrl').val();
        }

        $(this).find('[type=submit]').prop('disabled', true);

        request('/dashboard/api/transcription-providers', 'POST', {
            providers: { [$selected.data('provider')]: row },
        })
            .done((payload) =>
                reloadWith(payload.message || 'Provider saved successfully'),
            )
            .fail((xhr) => {
                notify(errorFrom(xhr), 'error');
                $(this).find('[type=submit]').prop('disabled', false);
            });
    });

    // Fallback priority: reorder the rows, then persist the new order.
    let draggedId = null;

    $('[data-provider-row]').on('dragstart', function () {
        draggedId = $(this).data('setting-id');
    });

    $('[data-provider-row]').on('dragover', (event) => event.preventDefault());

    $('[data-provider-row]').on('drop', function (event) {
        event.preventDefault();

        const $target = $(this);
        const targetId = $target.data('setting-id');

        if (!draggedId || !targetId || draggedId === targetId) {
            return;
        }

        const $list = $target.closest('[data-provider-list]');
        const $dragged = $list.find(`[data-setting-id="${draggedId}"]`);

        $target.before($dragged);
        draggedId = null;

        request('/dashboard/api/transcription-providers/order', 'POST', {
            category: $list.data('provider-list'),
            providers: $list
                .find('[data-provider-row]')
                .map((_, row) => $(row).data('setting-id'))
                .get(),
        })
            .done((payload) =>
                notify(payload.message || 'Provider fallback order updated'),
            )
            .fail((xhr) => notify(errorFrom(xhr), 'error'));
    });

    // Health badges are the one thing rendered after load; they are a status
    // lookup, not page structure.
    if ($('[data-provider-health]').length > 0) {
        request('/dashboard/api/transcription-providers/health')
            .done((payload) => {
                $('[data-provider-health]').each(function () {
                    const health =
                        payload.providers?.[$(this).data('provider-health')];

                    if (health) {
                        $(this)
                            .text(health.label)
                            .attr('title', health.message)
                            .attr('data-status', health.status);
                    }
                });
            })
            .fail(() => {
                $('[data-provider-health]')
                    .text('Check failed')
                    .attr(
                        'title',
                        'The server could not complete this provider check.',
                    )
                    .attr('data-status', 'offline');
            });
    }

    // --- Provider logs ----------------------------------------------------
    // The server returns a rendered blade partial, so the table markup stays
    // in blade rather than being built here.

    function loadLogs(category, page) {
        $('#logs-body').html(
            '<p class="py-8 text-center text-sm text-gray-500">Loading logs...</p>',
        );

        $('#logs-modal').data('page', page);

        // The endpoint returns `html` rendered by blade alongside the log data.
        request(
            `/dashboard/api/transcription-providers/logs?category=${category}&page=${page}&per_page=25`,
        )
            .done((payload) => $('#logs-body').html(payload.html))
            .fail(() =>
                $('#logs-body').html(
                    '<p class="py-8 text-center text-sm text-red-600">The provider log could not be loaded.</p>',
                ),
            );
    }

    $('[data-open-logs]').on('click', function () {
        const category = $(this).data('open-logs');

        $('#logs-modal').removeClass('hidden').data('category', category);
        $('#logs-title').text(`${$(this).data('logs-title')} Log`);
        loadLogs(category, 1);
    });

    $('#logs-modal').on('click', '[data-log-page]', function () {
        loadLogs($('#logs-modal').data('category'), $(this).data('log-page'));
    });

    $('#refresh-logs').on('click', function () {
        loadLogs(
            $('#logs-modal').data('category'),
            $('#logs-modal').data('page') || 1,
        );
    });

    // --- Transcriber package upload ---------------------------------------

    let packageFile = null;

    const packageLabel = () =>
        packageFile
            ? `${packageFile.name} (${(packageFile.size / (1024 * 1024)).toFixed(1)} MB)`
            : 'Choose a ZIP file or drag and drop it here';

    function choosePackage(file) {
        if (!file) {
            return false;
        }

        if (!file.name.toLowerCase().endsWith('.zip')) {
            notify('The Transcriber App Package must be a ZIP file.', 'error');

            return false;
        }

        if (file.size > 500 * 1024 * 1024) {
            notify(
                'The Transcriber App Package must not exceed 500 MB.',
                'error',
            );

            return false;
        }

        packageFile = file;
        $('#package-label').text(packageLabel());

        return true;
    }

    $('#package-input').on('change', function () {
        if (this.files[0] && !choosePackage(this.files[0])) {
            this.value = '';
        }
    });

    $('#package-dropzone')
        .on('dragenter dragover', function (event) {
            event.preventDefault();
            $(this).addClass('border-blue-500 bg-blue-50');
        })
        .on('dragleave drop', function (event) {
            event.preventDefault();
            $(this).removeClass('border-blue-500 bg-blue-50');
        })
        .on('drop', (event) =>
            choosePackage(event.originalEvent.dataTransfer.files[0]),
        );

    const setProgress = (percent, text) => {
        $('#package-progress-bar')
            .css('width', `${percent}%`)
            .toggleClass('bg-green-600', percent === 100);
        $('#package-progress-percent').text(`${percent}%`);
        $('#package-progress-text').text(text);
    };

    /** One chunk, with upload progress. XHR is used for the progress event. */
    function sendChunk(url, formData, onProgress) {
        return $.ajax({
            url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            xhr() {
                const xhr = $.ajaxSettings.xhr();

                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        onProgress?.(event.loaded);
                    }
                });

                return xhr;
            },
        });
    }

    async function sha256Hex(blob) {
        const digest = await crypto.subtle.digest(
            'SHA-256',
            await blob.arrayBuffer(),
        );

        return [...new Uint8Array(digest)]
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');
    }

    $('#upload-package').on('click', async function () {
        const version = $('#transcriberVersion').val();

        if (!packageFile) {
            notify('Choose or drop a ZIP package before uploading.', 'error');

            return;
        }

        if (!version) {
            notify('Enter a package version before uploading.', 'error');

            return;
        }

        $(this).prop('disabled', true);
        $('#package-idle').addClass('hidden');
        $('#package-progress').removeClass('hidden');
        setProgress(0, 'Uploading package...');

        const uploadId =
            crypto.randomUUID?.() ??
            `upload-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const totalChunks = Math.max(
            1,
            Math.ceil(packageFile.size / UPLOAD_CHUNK_BYTES),
        );
        let uploadedBytes = 0;

        try {
            for (let index = 0; index < totalChunks; index += 1) {
                const start = index * UPLOAD_CHUNK_BYTES;
                const chunk = packageFile.slice(
                    start,
                    Math.min(packageFile.size, start + UPLOAD_CHUNK_BYTES),
                );
                const formData = new FormData();

                formData.append('upload_id', uploadId);
                formData.append('chunk_index', String(index));
                formData.append('total_chunks', String(totalChunks));
                formData.append('total_size', String(packageFile.size));
                formData.append('filename', packageFile.name);
                formData.append(
                    'mime_type',
                    packageFile.type || 'application/zip',
                );
                formData.append('chunk_hash', await sha256Hex(chunk));
                formData.append(
                    'chunk',
                    chunk,
                    `${packageFile.name}.part${index}`,
                );

                await sendChunk(
                    '/dashboard/api/transcriber-package/chunk',
                    formData,
                    (loaded) => {
                        setProgress(
                            Math.min(
                                99,
                                Math.round(
                                    ((uploadedBytes + loaded) /
                                        packageFile.size) *
                                        100,
                                ),
                            ),
                            `Uploading package chunk ${index + 1} of ${totalChunks}...`,
                        );
                    },
                );

                uploadedBytes += chunk.size;
            }

            setProgress(99, 'Processing package...');

            const completeForm = new FormData();
            completeForm.append('upload_id', uploadId);
            completeForm.append('version', version);

            const payload = await sendChunk(
                '/dashboard/api/transcriber-package/complete',
                completeForm,
            );

            setProgress(100, 'Upload complete');
            reloadWith(
                payload.message ||
                    'Transcriber App Package uploaded successfully',
            );
        } catch (xhr) {
            notify(errorFrom(xhr), 'error');
            setProgress(0, 'Uploading package...');
            $('#package-idle').removeClass('hidden');
            $('#package-progress').addClass('hidden');
            $(this).prop('disabled', false);
        }
    });

    // --- Modals -----------------------------------------------------------

    $('[data-open-modal]').on('click', function () {
        $($(this).data('open-modal')).removeClass('hidden');
    });

    $('[data-close-modal]').on('click', function () {
        $($(this).data('close-modal')).addClass('hidden');
    });

    $('[data-modal]').on('click', function (event) {
        if (event.target === this) {
            $(this).addClass('hidden');
        }
    });

    $(document).on('keydown', (event) => {
        if (event.key === 'Escape') {
            $('[data-modal]').addClass('hidden');
        }
    });

    // Add offers the providers in this group that are not connected yet.
    $('[data-add-provider]').on('click', function () {
        const category = $(this).data('add-provider');
        const $available = showProviderOptions(
            ($option) =>
                $option.data('category') === category &&
                !$option.data('configured'),
        );

        if ($available.length === 0) {
            notify(
                'All available providers in this group have already been added.',
                'info',
            );

            return;
        }

        $('#provider-form')[0].reset();
        $('#providerSelect')
            .prop('disabled', false)
            .val($available.first().val())
            .trigger('change');
        $('#provider-modal-title').text('Add Provider');
        $('#provider-modal').removeClass('hidden');
    });

    // Edit opens the provider modal pre-filled from the row's data attributes.
    $('[data-edit-provider]').on('click', function () {
        const $row = $(this).closest('[data-provider-row]');
        const key = $row.data('integration-key');

        showProviderOptions(($option) => $option.val() === key);

        $('#providerSelect')
            .val(key)
            .prop('disabled', true)
            .trigger('change');
        $('#provider-modal-title').text(`Edit ${$row.data('provider-name')}`);
        $('#provider-modal').removeClass('hidden');
    });
});
