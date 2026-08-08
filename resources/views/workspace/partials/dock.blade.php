<div class="pointer-events-none absolute inset-x-0 bottom-0 px-3 py-3 lg:px-6 lg:py-4">
    <div class="pointer-events-auto mx-auto flex w-full max-w-[calc(100%-1rem)] flex-col items-center justify-center gap-3 lg:max-w-[calc(100%-2rem)] lg:gap-4">

        <input type="file" id="upload-input" class="hidden"
               accept="audio/*,.wav,.mp3,.m4a,.aac,.ogg,.flac,.webm">

        <div id="mode-choose" class="{{ config('ui.workspace.dock.panel') }} mx-auto" @if ($mode !== 'choose') hidden @endif>
            <button type="button" id="choose-live" class="{{ config('ui.workspace.dock.mode_button') }}">Live</button>
            <button type="button" id="choose-upload" class="{{ config('ui.workspace.dock.mode_button') }}">Upload Audio</button>
        </div>

        <div id="mode-live" class="{{ config('ui.workspace.dock.panel') }}" @if ($mode !== 'live') hidden @endif>
            <button type="button" id="live-toggle" aria-pressed="false"
                    class="group flex h-12 min-w-32 flex-1 cursor-pointer items-center justify-center gap-3 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition outline-none hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60 sm:min-w-40 sm:flex-none">
                <x-icon name="play" id="live-icon-play" class="size-4 fill-current" />
                <x-icon name="square" id="live-icon-stop" class="size-4 fill-current" hidden />
                <span class="grid text-left leading-none">
                    <span id="live-button-top" class="text-xs font-semibold text-blue-200 uppercase">Listening</span>
                    <span id="live-button-bottom" class="mt-1 text-sm font-semibold text-white">Ready to capture</span>
                </span>
            </button>

            <div id="live-panel" class="w-full min-w-0 flex-none sm:w-80" hidden>
                <div class="flex min-w-0 items-center gap-2 text-sm">
                    <span id="live-active-name" class="shrink-0 font-semibold text-slate-950">Ready</span>
                    <span id="live-range" class="min-w-0 truncate text-slate-500"></span>
                    <span id="live-elapsed" class="ml-auto shrink-0 font-semibold text-blue-700">00:00:00</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div id="live-progress" class="h-full rounded-full bg-blue-600 transition-[width] duration-150" style="width: 0%"></div>
                </div>
                <p id="live-support" class="mt-1 text-xs font-medium text-slate-500">Ready</p>
            </div>
        </div>

        <div id="mode-upload" class="{{ config('ui.workspace.dock.panel') }}" @if ($mode !== 'upload') hidden @endif>
            <button type="button" id="upload-browse"
                    @disabled(! $canUseUpload)
                    class="inline-flex h-12 min-w-28 flex-1 cursor-pointer items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-32 sm:flex-none">
                Browse
            </button>

            <div id="upload-detail" class="w-full min-w-0 flex-none sm:w-80" hidden>
                <p id="upload-filename" class="truncate text-sm font-semibold text-slate-950">Select an audio file</p>
                <p id="upload-meta" class="truncate text-xs text-slate-500">WAV, MP3, M4A, AAC, OGG, FLAC.</p>
                <p class="text-xs text-slate-500">
                    Duration: <span id="upload-duration" class="font-semibold text-slate-700">--:--</span>
                </p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div id="upload-progress" class="h-full rounded-full bg-blue-600 transition-[width] duration-150" style="width: 0%"></div>
                </div>
            </div>

            <span id="upload-status" class="max-w-28 truncate text-xs font-semibold text-slate-700" hidden></span>
            <span id="upload-percent" class="w-10 text-right text-xs font-semibold text-blue-700" hidden></span>

            <button type="button" id="upload-start" hidden
                    class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 sm:flex-none">
                Start
            </button>
            <button type="button" id="upload-pause" hidden
                    class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 sm:flex-none">
                Pause
            </button>
            <button type="button" id="upload-continue" hidden
                    class="h-12 min-w-24 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 sm:flex-none">
                Continue
            </button>
            <button type="button" id="upload-retry" hidden
                    class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 sm:flex-none">
                Retry
            </button>
            <button type="button" id="upload-cancel" hidden
                    class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 sm:flex-none">
                Cancel
            </button>
        </div>

        @if ($showActions)
            <div class="order-2 flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.08)] sm:w-fit"
                 data-transcript-id="{{ $primaryTranscript['id'] ?? '' }}">

                <button type="button" data-open-modal="#polish-modal" class="{{ config('ui.workspace.dock.action_primary') }}"
                        @disabled(! $primaryTranscript || ($primaryTranscript['polish_status'] ?? '') === 'processing')>
                    <x-icon name="sparkles" />
                    {{ ($primaryTranscript['polish_status'] ?? '') === 'processing' ? 'Polishing' : 'Polish' }}
                </button>

                @if ($primaryTranscript && $primaryTranscript['can_undo_polish'])
                    <button type="button" id="undo-polish" class="{{ config('ui.workspace.dock.action_plain') }}"
                            @disabled($primaryTranscript['polish_status'] === 'processing')>
                        <x-icon name="undo-2" />
                        Undo
                    </button>
                @endif

                <button type="button" data-open-modal="#summary-modal" class="{{ config('ui.workspace.dock.action_primary') }}"
                        @disabled(! $primaryTranscript)>
                    <x-icon name="file-text" />
                    Summary
                </button>

                <button type="button" data-open-modal="#export-modal" class="{{ config('ui.workspace.dock.action_plain') }}"
                        @disabled(! $primaryTranscript)>
                    <x-icon name="download" />
                    Export
                </button>

                <button type="button" data-open-modal="#log-modal" class="{{ config('ui.workspace.dock.action_plain') }}"
                        @disabled(! $primaryTranscript)>
                    <x-icon name="list-checks" />
                    Log
                </button>
            </div>
        @endif
    </div>
</div>

@if ($showActions && $primaryTranscript)
    @include('workspace.partials.action-modals')
@endif
