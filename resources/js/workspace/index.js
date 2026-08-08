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

    // --- Sidebar ------------------------------------------------------------

    const sidebarStorageKey = 'jerva.workspace.sidebar-hidden';
    const desktopSidebar = window.matchMedia('(min-width: 1024px)');
    const $sidebar = $('#workspace-sidebar');
    let sidebarVisible = false;
    let sidebarPreference = readSidebarPreference();

    function readSidebarPreference() {
        try {
            const stored = localStorage.getItem(sidebarStorageKey);

            return stored === null ? null : stored === 'true';
        } catch {
            return null;
        }
    }

    function setSidebarVisible(visible, persist = false) {
        sidebarVisible = visible;

        $sidebar
            .prop('hidden', !visible)
            .toggleClass('hidden', !visible)
            .toggleClass('flex', visible);
        $('#workspace-sidebar-toggle')
            .attr('aria-expanded', String(visible))
            .attr('aria-label', visible ? 'Hide sidebar' : 'Show sidebar');
        $('#workspace-sidebar-backdrop').prop(
            'hidden',
            !visible || desktopSidebar.matches,
        );
        $root[0].style.setProperty(
            '--workspace-sidebar-width',
            visible && desktopSidebar.matches ? '19rem' : '0rem',
        );
        document.body.classList.toggle(
            'overflow-hidden',
            visible && !desktopSidebar.matches,
        );

        if (persist) {
            sidebarPreference = !visible;

            try {
                localStorage.setItem(
                    sidebarStorageKey,
                    String(sidebarPreference),
                );
            } catch {
                // The sidebar still toggles when browser storage is unavailable.
            }
        }
    }

    setSidebarVisible(
        sidebarPreference === null
            ? desktopSidebar.matches
            : !sidebarPreference,
    );

    $('#workspace-sidebar-toggle').on('click', () => {
        setSidebarVisible(!sidebarVisible, true);
    });

    $('#workspace-sidebar-backdrop, #workspace-sidebar-close').on(
        'click',
        () => {
            setSidebarVisible(false, true);
        },
    );

    desktopSidebar.addEventListener('change', () => {
        setSidebarVisible(
            sidebarPreference === null
                ? desktopSidebar.matches
                : !sidebarPreference,
        );
    });

    // True while the summarize POST is in flight, so a poll landing mid-request
    // cannot replace the panel under the click.
    let summaryBusy = false;

    // True while any transcript action is in flight; locks the others.
    let acting = false;

    // Last action state the server reported, so a repaint can reapply the lock.
    // Seeded from the page render, otherwise the first click before any poll
    // would find no state to repaint from and skip the lock. jQuery has already
    // parsed the JSON off the attribute.
    let lastActions = $root.data('actions') ?? null;

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
            // `html` and `summary` are rendered by blade; the project payload
            // only feeds upload progress, never markup.
            $('#transcript-body').html(payload.html);

            // Never swap the summary panel out from under a click.
            if (payload.summary && !summaryBusy) {
                $('#summary-panel').html(payload.summary);
            }

            paintActions(payload.actions);
            transcripts = payload.project?.transcripts ?? [];
            upload.syncTranscripts(transcripts);
            bindRetry();
        });
    }

    /**
     * Paints the polish and summary controls from the server's view of the
     * job. A queued job keeps its button disabled and spinning until a later
     * poll reports it finished.
     *
     * Called with no argument to repaint from the last known server state,
     * which is how the acting lock is applied and released.
     */
    function paintActions(actions) {
        if (actions) {
            lastActions = actions;
        }

        if (!lastActions) {
            return;
        }

        const state = lastActions;
        const polishing = Boolean(state.polishing);
        // One action at a time, the way the old dialogs shared `isActing`.
        const busy = acting || polishing;

        $('#open-polish').prop('disabled', busy);
        $('#open-polish-label').text(state.polish_label);
        $('#polish-icon').prop('hidden', polishing);
        $('#open-polish-spinner').prop('hidden', !polishing);

        $('#polish-submit').prop('disabled', busy || !state.has_raw);
        $('#polish-submit-label').text(state.polish_label);
        $('#polish-spinner').prop('hidden', !polishing);
        $('#polish-replace-note').prop('hidden', !state.has_cleaned_text);

        $('#undo-polish')
            .prop('hidden', !state.can_undo_polish)
            .prop('disabled', busy);

        $('#open-summary').prop('disabled', acting);
        $('#summary-icon').prop('hidden', Boolean(state.summarizing));
        $('#open-summary-spinner').prop('hidden', !state.summarizing);

        // Lives in the panel the poll replaces, so re-apply the lock each time.
        $('#summary-create').prop(
            'disabled',
            acting || state.summarizing || !state.has_raw,
        );
    }

    /** Locks every transcript action while one of them is in flight. */
    function setActing(active) {
        acting = active;
        paintActions();
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

    // --- Dock visibility ----------------------------------------------------

    const dockStorageKey = 'jerva.workspace.controls-hidden';
    let dockHidden = false;

    function readDockPreference() {
        try {
            return localStorage.getItem(dockStorageKey) === 'true';
        } catch {
            return false;
        }
    }

    function setDockHidden(hidden, persist = false) {
        dockHidden = hidden;

        $('#workspace-dock-controls').prop('hidden', hidden);
        $('#workspace-transcript-scroll').css(
            'padding-bottom',
            hidden ? '5rem' : '',
        );
        $('#workspace-dock-toggle')
            .attr('aria-expanded', String(!hidden))
            .attr(
                'title',
                hidden ? 'Show workspace controls' : 'Hide workspace controls',
            );
        $('#workspace-dock-hide-icon').prop('hidden', hidden);
        $('#workspace-dock-show-icon').prop('hidden', !hidden);
        $('#workspace-dock-toggle-label').text(
            hidden ? 'Show controls' : 'Hide controls',
        );

        if (persist) {
            try {
                localStorage.setItem(dockStorageKey, String(hidden));
            } catch {
                // The controls still work when browser storage is unavailable.
            }
        }
    }

    setDockHidden(readDockPreference());

    $('#workspace-dock-toggle').on('click', () => {
        setDockHidden(!dockHidden, true);
    });

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

        // Stays disabled after the request: refreshStatus paints the queued
        // job, and only a later poll reporting it finished re-enables it.
        setActing(true);
        $('#polish-spinner').prop('hidden', false);
        $('#polish-submit-label').text('Polishing');
        showUpgrade('');

        postAction(actionUrl('polish'), { preset, instruction })
            .done(() => {
                $('#polish-modal').addClass('hidden');
                polling.start();
                // The server now reports polish_status=processing, so the
                // buttons stay down after the lock lifts. Wrapped because
                // refreshStatus may hand back a native promise.
                void Promise.resolve(refreshStatus()).finally(() =>
                    setActing(false),
                );
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

                // The job never started, so hand the buttons back.
                $('#polish-spinner').prop('hidden', true);
                $('#polish-submit-label').text('Polish');
                setActing(false);
            });
    });

    // Undo completes inside the request, so the buttons come straight back.
    $('#undo-polish').on('click', function () {
        setActing(true);
        $('#undo-polish-label').text('Undoing');

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
            .always(() => {
                $('#undo-polish-label').text('Undo');
                setActing(false);
            });
    });

    // Delegated: refreshStatus replaces #summary-panel, so the button this
    // fires on is not the one that was in the DOM at load.
    $(document).on('click', '#summary-create', function () {
        $(this).prop('disabled', true).text('Summarizing');
        summaryBusy = true;
        setActing(true);
        showUpgrade('');

        const release = () => {
            summaryBusy = false;

            // Repaints the panel with the progress bar and status pill.
            void Promise.resolve(refreshStatus()).finally(() =>
                setActing(false),
            );
        };

        postAction(actionUrl('summarize'))
            .done(() => {
                polling.start();
                release();
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

                release();
            });
    });

    /** Exports stream a file, so the browser download path is used directly. */
    /**
     * Builds the file and hands it to the browser. The export finishes within
     * this request, so the caller restores the button afterwards rather than
     * waiting on a poll.
     */
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
        const filename =
            disposition?.match(/filename="?([^"]+)"?/i)?.[1] ??
            `transcript.${format}`;
        const link = document.createElement('a');

        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
        notify(`Export download started: ${filename}`);
    }

    /**
     * Holds a group of buttons down while one of them runs, and shows the
     * running label on the one that was clicked.
     */
    async function whileExporting($clicked, run) {
        const label = $clicked.text().trim();
        const $all = $(
            '[data-export-transcript], [data-summary-export-format]',
        );

        $all.prop('disabled', true);
        $clicked.text('Exporting');

        try {
            await run();
        } finally {
            $clicked.text(label);
            $all.prop('disabled', false);
            // The server decides what stays disabled once the panel repaints.
            void refreshStatus();
        }
    }

    $('[data-export-transcript]').on('click', function () {
        void whileExporting($(this), () =>
            exportTranscript(
                $(this).data('export-transcript'),
                $('#export-source').val(),
            ),
        );
    });

    // Delegated for the same reason as #summary-create.
    $(document).on('click', '[data-summary-export-format]', function () {
        void whileExporting($(this), () =>
            exportTranscript($(this).data('summary-export-format'), 'summary'),
        );
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

            if (sidebarVisible && !desktopSidebar.matches) {
                setSidebarVisible(false, true);
            }
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
