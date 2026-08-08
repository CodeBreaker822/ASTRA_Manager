<div id="polish-modal" data-modal class="{{ config('ui.workspace.modal.shell') }}">
    <div class="{{ config('ui.workspace.modal.card') }} max-w-lg">
        <h3 class="text-base font-semibold text-slate-950">Polish transcript</h3>
        <p class="mt-1 text-sm text-slate-600">Choose a preset or write your own instructions.</p>

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
            @foreach (config('workspace.polish_presets') as $key => $preset)
                <button type="button"
                        data-polish-preset="{{ $key }}"
                        data-instruction="{{ $preset['instruction'] }}"
                        @class([
                            'rounded-lg border px-3 py-2 text-left text-sm font-medium transition',
                            'border-blue-300 bg-blue-50 text-blue-800' => $key === config('workspace.default_polish_preset'),
                            'border-slate-200 text-slate-700 hover:border-blue-300 hover:bg-blue-50' => $key !== config('workspace.default_polish_preset'),
                        ])>
                    {{ $preset['label'] }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 grid gap-2">
            <x-ui.label for="polish-instruction">Instructions</x-ui.label>
            <x-ui.textarea id="polish-instruction" rows="5">{{ config('workspace.polish_presets.'.config('workspace.default_polish_preset').'.instruction') }}</x-ui.textarea>
            <p id="polish-error" class="text-sm font-medium text-destructive" hidden></p>
            <p id="polish-replace-note" class="text-sm text-slate-600" @if (! $actions['has_cleaned_text']) hidden @endif>
                Polishing again starts from the version now displayed. You can undo it afterward.
            </p>
        </div>

        @unless ($hasRaw)
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                No raw transcript is ready to polish yet.
            </p>
        @endunless

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" data-close-modal="#polish-modal" class="{{ config('ui.workspace.modal.cancel') }}">Cancel</button>
            <button type="button" id="polish-submit"
                    @disabled(! $hasRaw || $actions['polishing'])
                    class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                <span id="polish-spinner" class="inline-flex" @if (! $actions['polishing']) hidden @endif>
                    <x-ui.spinner />
                </span>
                <span id="polish-submit-label">{{ $actions['polishing'] ? 'Polishing' : 'Polish' }}</span>
            </button>
        </div>
    </div>
</div>

<div id="summary-modal" data-modal class="{{ config('ui.workspace.modal.shell') }}">
    <div class="{{ config('ui.workspace.modal.card') }} max-w-2xl">
        <div class="flex items-start justify-between gap-3 pb-2">
            <h3 class="text-base font-semibold text-slate-950">{{ $title }}</h3>
            <button type="button" data-close-modal="#summary-modal" class="text-slate-400 hover:text-slate-600">
                <x-icon name="x" class="size-5" />
            </button>
        </div>

        <div id="summary-panel">
            @include('workspace.partials.summary-panel')
        </div>
    </div>
</div>

<div id="export-modal" data-modal class="{{ config('ui.workspace.modal.shell') }}">
    <div class="{{ config('ui.workspace.modal.card') }} max-w-md">
        <h3 class="text-base font-semibold text-slate-950">Export transcript</h3>

        <div class="mt-4 grid gap-2">
            <x-ui.label for="export-source">Content</x-ui.label>
            <x-ui.select id="export-source" :options="config('workspace.export_sources')" selected="raw" />
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @foreach (config('workspace.export_formats') as $format => $label)
                <button type="button" data-export-transcript="{{ $format }}"
                        class="h-10 flex-1 cursor-pointer rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 flex justify-end">
            <button type="button" data-close-modal="#export-modal" class="{{ config('ui.workspace.modal.cancel') }}">Close</button>
        </div>
    </div>
</div>

<div id="log-modal" data-modal class="{{ config('ui.workspace.modal.shell') }}">
    <div class="{{ config('ui.workspace.modal.card') }} max-w-3xl">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-slate-950">Processing log</h3>
                <p class="mt-1 text-sm text-slate-600">{{ $title }}</p>
            </div>
            <button type="button" data-close-modal="#log-modal" class="text-slate-400 hover:text-slate-600">
                <x-icon name="x" class="size-5" />
            </button>
        </div>

        <div class="mt-4 max-h-[55vh] overflow-y-auto">
            @forelse ($primaryTranscript['processing_log'] ?? [] as $entry)
                <div class="border-b border-slate-100 py-3 last:border-b-0">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-950">{{ $entry['status'] }}</span>
                        <span class="shrink-0 text-xs text-slate-500">{{ $entry['logged_at'] }}</span>
                    </div>
                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ $entry['message'] }}</p>
                </div>
            @empty
                <p class="py-8 text-center text-sm text-slate-500">No processing activity has been recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
