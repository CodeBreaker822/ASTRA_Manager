<div class="flex items-center justify-between gap-3 border-b border-blue-100 px-1 pb-3">
    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">Summary</p>
    <span @class([
        'inline-flex h-8 items-center rounded-lg border px-3 text-xs font-semibold',
        'border-blue-200 bg-blue-50 text-blue-800' => $actions['summary_status'] !== 'failed',
        'border-red-200 bg-red-50 text-red-700' => $actions['summary_status'] === 'failed',
    ])>{{ $actions['summary_status_label'] }}</span>
</div>

@if ($actions['summarizing'])
    <div class="h-1 overflow-hidden bg-blue-100" role="progressbar" aria-label="Summarizing">
        <div class="h-full w-full animate-pulse bg-blue-600"></div>
    </div>
@endif

<div class="mt-4 max-h-[50vh] overflow-y-auto rounded-lg border border-blue-100 bg-blue-50/50 p-4 text-sm leading-6 text-slate-800">
    @if (filled($summaryText))
        {!! \App\Support\SummaryMarkdown::render($summaryText) !!}
    @elseif ($actions['summarizing'])
        <p class="text-blue-900">
            The summary is being prepared. You may close this window and return later.
        </p>
    @else
        <p class="text-blue-900">No summary has been created for this project.</p>
    @endif

    @if ($actions['summary_error'])
        <p class="mt-3 border-l-2 border-red-500 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">
            {{ $actions['summary_error'] }}
        </p>
    @endif
</div>

<div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-blue-900">Starting again replaces this project's existing summary.</p>

    <div class="flex flex-wrap justify-end gap-2">
        @foreach (config('workspace.export_formats') as $format => $label)
            <button type="button" data-summary-export-format="{{ $format }}"
                    @disabled(! $actions['has_summary'] || $actions['summary_status'] !== 'complete')
                    class="h-10 cursor-pointer rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">
                {{ $label }}
            </button>
        @endforeach

        <button type="button" id="summary-create"
                @disabled(! $hasRaw || $actions['summarizing'])
                class="h-10 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
            {{ $actions['summarizing'] ? 'Summarizing' : $actions['summary_button_label'] }}
        </button>
    </div>
</div>
