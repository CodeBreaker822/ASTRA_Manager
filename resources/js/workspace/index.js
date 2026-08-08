/**
 * Workspace glue.
 *
 * Blade renders the page. This file only handles what the browser must do
 * live: capture audio, upload chunks, poll for progress, and open modals.
 * The transcript body is re-rendered by the server and swapped in as HTML.
 */
import { createPolling } from './polling.js';
import { createRecorder } from './recorder.js';
import { createUpload } from './upload.js';

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]').content;
const notify = (message, type = 'success') =>
    window.showNotification(message, type);

$(function () {
    const $root = $('[data-workspace]');

    if ($root.length === 0) {
        return;
    }

    const projectId = $root.data('project-id') || null;
    const canUseLive = Boolean($root.data('can-use-live'));

    let transcripts = [];

    const showUpgrade = (message) => {
        $('#upgrade-message').text(message);
        $('#upgrade-banner').prop('hidden', !message);
    };

    // --- Server-rendered refresh -------------------------------------------

    function refreshStatus() {
        if (!projectId) {
            return Promise.resolve();
        }

        return $.ajax({
            url: `/workspace/${projectId}/status`,
            dataType: 'json',
            headers: { Accept: 'application/json' },
        }).then((payload) => {
            // `html` is rendered by blade; the project payload only feeds
            // upload progress, never markup.
            $('#transcript-body').html(payload.html);
            transcripts = payload.project?.transcripts ?? [];
            upload.syncTranscripts(transcripts);
            bindRetry();
        });
    }

    const hasPendingWork = () =>
        transcripts.some(
            (t) =>
                ['queued', 'processing'].includes(t.status) ||
                t.polish_status === 'processing' ||
                t.summary_status === 'processing',
        );

    const polling = createPolling({
        hasWork: hasPendingWork,
        refresh: refreshStatus,
        onError: (message) => notify(message, 'error'),
    });

    // --- Upload -------------------------------------------------------------

    const upload = createUpload({
        csrfToken,
        projectId: () => projectId,
        knownTranscriptIds: () => transcripts.map((t) => t.id),
        refreshStatus,
        onTranscript: () => polling.start(),
        onQueued: () => polling.start(),
        onUpgrade: showUpgrade,
        onSuccess: (message) => {
            notify(message);
            void refreshStatus();
        },
        onError: (message) => notify(message, 'error'),
    });

    /** Mirrors upload state onto the blade-rendered dock. */
    function paintUpload() {
        const visible = upload.hasFile || upload.isUploadActive;

        $('#upload-detail').prop('hidden', !visible);
        $('#upload-status')
            .prop('hidden', !visible)
            .text(upload.uploadStatusLine);
        $('#upload-percent')
            .prop('hidden', !visible)
            .text(`${upload.progressPercent}%`);
        $('#upload-filename').text(upload.uploadFileName);
        $('#upload-meta').text(
            upload.uploadMetaLine || 'WAV, MP3, M4A, AAC, OGG, FLAC.',
        );
        $('#upload-duration').text(upload.uploadDurationLabel);
        $('#upload-progress').css('width', `${upload.progressPercent}%`);

        $('#upload-start')
            .prop('hidden', !visible)
            .prop('disabled', !upload.canStart);
        $('#upload-pause').prop('hidden', !upload.canPause);
        $('#upload-continue').prop('hidden', !upload.canContinue);
        $('#upload-retry').prop('hidden', !upload.canRetry);
        $('#upload-cancel').prop('hidden', !upload.canCancel);
        $('#upload-browse').prop(
            'disabled',
            upload.uploadInFlight || upload.isPreparing,
        );
    }

    // The upload object mutates itself, so repaint on a short interval while
    // anything is in flight rather than wiring a callback into every branch.
    window.setInterval(() => {
        if (upload.hasFile || upload.isUploadActive) {
            paintUpload();
        }
    }, 200);

    $('#upload-browse, #choose-upload').on('click', function () {
        setMode('upload');

        if (this.id === 'upload-browse') {
            $('#upload-input').trigger('click');
        }
    });

    $('#upload-input').on('change', async function () {
        const file = this.files?.[0];
        this.value = '';

        if (file) {
            setMode('upload');
            await upload.selectFile(file);
            paintUpload();
        }
    });

    $('#upload-start').on('click', () =>
        upload.startUpload().then(paintUpload),
    );
    $('#upload-pause').on('click', () => {
        upload.pauseUpload();
        paintUpload();
    });
    $('#upload-continue').on('click', () =>
        upload.resumeUpload().then(paintUpload),
    );
    $('#upload-retry').on('click', () =>
        upload.retryUpload().then(paintUpload),
    );
    $('#upload-cancel').on('click', () => {
        upload.cancelUpload();
        paintUpload();
    });

    function bindRetry() {
        $('#retry-upload')
            .prop('hidden', !upload.canRetry)
            .off('click')
            .on('click', () => upload.retryUpload().then(paintUpload));
    }

    // --- Live recording ------------------------------------------------------

    const recorder = createRecorder({
        csrfToken,
        projectId: () => projectId,
        canUseLive: () => canUseLive,
        captureScreenAudio: () =>
            localStorage.getItem('jerva.capture-screen-audio') === 'true',
        onTranscript: () => polling.start(),
        onQueued: () => polling.start(),
        onUpgrade: showUpgrade,
        onToastError: (message) => notify(message, 'error'),
    });

    /** Mirrors recorder state onto the blade-rendered dock. */
    function paintLive() {
        $('#live-button-top').text(recorder.liveButtonTop);
        $('#live-button-bottom').text(recorder.liveButtonBottom);
        $('#live-toggle').attr('aria-pressed', recorder.isRecording);
        $('#live-icon-play').prop('hidden', recorder.isRecording);
        $('#live-icon-stop').prop('hidden', !recorder.isRecording);
        $('#live-panel').prop('hidden', !recorder.isLivePanelVisible);
        $('#live-active-name').text(recorder.liveActiveName);
        $('#live-range').text(recorder.liveCurrentRangeLabel);
        $('#live-elapsed').text(recorder.liveElapsedLabel);
        $('#live-progress').css('width', `${recorder.liveSegmentProgress}%`);
        $('#live-support').text(recorder.liveSupportLine);
    }

    window.setInterval(paintLive, 150);

    $('#choose-live').on('click', () => setMode('live'));

    $('#live-toggle').on('click', async () => {
        showUpgrade('');

        if (!canUseLive && !recorder.isRecording) {
            showUpgrade(
                'Live transcription is not available for this account.',
            );

            return;
        }

        await recorder.toggleLive();
        paintLive();
    });

    // A full page load during a recording would drop unsent audio.
    $(window).on('beforeunload', (event) => {
        if (recorder.isRecording || recorder.hasUnsavedChunks) {
            event.preventDefault();
            event.originalEvent.returnValue = '';
        }
    });

    // --- Mode switching ------------------------------------------------------

    function setMode(mode) {
        $('#mode-choose').prop('hidden', mode !== 'choose');
        $('#mode-live').prop('hidden', mode !== 'live');
        $('#mode-upload').prop('hidden', mode !== 'upload');
    }

    // --- Transcript actions --------------------------------------------------

    const transcriptId = () => $('[data-transcript-id]').data('transcript-id');

    function actionUrl(suffix) {
        return `/workspace/${projectId}/transcripts/${transcriptId()}/${suffix}`;
    }

    function postAction(url, body) {
        return $.ajax({
            url,
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(body ?? {}),
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
    }

    $('[data-polish-preset]').on('click', function () {
        $('[data-polish-preset]')
            .removeClass('border-blue-300 bg-blue-50 text-blue-800')
            .addClass('border-slate-200 text-slate-700');
        $(this)
            .addClass('border-blue-300 bg-blue-50 text-blue-800')
            .removeClass('border-slate-200 text-slate-700');
        $('#polish-instruction').val($(this).data('instruction'));
        $('#polish-error').prop('hidden', true);
    });

    $('#polish-submit').on('click', function () {
        const instruction = String($('#polish-instruction').val() ?? '').trim();

        if (instruction.length < 3) {
            $('#polish-error')
                .text('Enter instructions before polishing.')
                .prop('hidden', false);

            return;
        }

        const preset =
            $('[data-polish-preset].bg-blue-50').data('polish-preset') ??
            'custom';

        $(this).prop('disabled', true);
        showUpgrade('');

        postAction(actionUrl('polish'), { preset, instruction })
            .done(() => {
                $('#polish-modal').addClass('hidden');
                polling.start();
                void refreshStatus();
            })
            .fail((xhr) => {
                const payload = xhr.responseJSON ?? {};
                const message =
                    payload.message ?? 'Transcript could not be polished.';

                if (payload.upgrade) {
                    showUpgrade(message);
                } else {
                    notify(message, 'error');
                }
            })
            .always(() => $(this).prop('disabled', false));
    });

    $('#undo-polish').on('click', function () {
        $(this).prop('disabled', true);

        postAction(actionUrl('polish/undo'))
            .done((payload) => {
                notify(payload.message ?? 'Polish undone.');
                void refreshStatus();
            })
            .fail((xhr) =>
                notify(
                    xhr.responseJSON?.message ?? 'Polish could not be undone.',
                    'error',
                ),
            )
            .always(() => $(this).prop('disabled', false));
    });

    $('#summary-create').on('click', function () {
        $(this).prop('disabled', true);
        showUpgrade('');

        postAction(actionUrl('summarize'))
            .done(() => {
                polling.start();
                void refreshStatus();
                notify('Summary requested.');
            })
            .fail((xhr) => {
                const payload = xhr.responseJSON ?? {};
                const message =
                    payload.message ??
                    'The transcript could not be summarized.';

                if (payload.upgrade) {
                    showUpgrade(message);
                } else {
                    notify(message, 'error');
                }
            })
            .always(() => $(this).prop('disabled', false));
    });

    /** Exports stream a file, so the browser download path is used directly. */
    async function exportTranscript(format, source) {
        const url = `${actionUrl('export')}?format=${format}&source=${source}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/octet-stream,application/json' },
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const message =
                payload.message ?? 'No transcription is ready to export yet.';

            if (payload.upgrade) {
                showUpgrade(message);
            } else {
                notify(message, 'error');
            }

            return;
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition');
        const link = document.createElement('a');

        link.href = URL.createObjectURL(blob);
        link.download =
            disposition?.match(/filename="?([^"]+)"?/i)?.[1] ??
            `transcript.${format}`;
        link.click();
        URL.revokeObjectURL(link.href);
    }

    $('[data-export-transcript]').on('click', function () {
        void exportTranscript(
            $(this).data('export-transcript'),
            $('#export-source').val(),
        );
    });

    $('[data-summary-export-format]').on('click', function () {
        void exportTranscript($(this).data('export-summary'), 'summary');
    });

    // --- Project delete ------------------------------------------------------

    $('[data-delete-project]').on('click', function () {
        const id = $(this).data('delete-project');
        const title = $(this).data('project-title');
        const count = Number($(this).data('transcripts-count'));

        $('#delete-project-title').text(`Delete "${title}"?`);
        $('#delete-project-body').text(
            `This permanently deletes ${
                count > 0
                    ? `${count} ${count === 1 ? 'transcript' : 'transcripts'} and all of its`
                    : 'this transcript and its'
            } audio. This cannot be undone.`,
        );
        $('#delete-project-form').attr('action', `/workspace/${id}`);
        $('#delete-project-modal').removeClass('hidden');
    });

    // --- Modals ---------------------------------------------------------------

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

    // --- Boot ------------------------------------------------------------------

    if (projectId) {
        void refreshStatus().then(() => {
            if ($root.data('has-pending')) {
                polling.start();
            }
        });
    }
});
