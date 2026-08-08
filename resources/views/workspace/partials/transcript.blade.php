{{-- Rows, failure state, and empty copy are all resolved by App\Support\WorkspaceView. --}}
@if ($rows !== [])
    @foreach ($rows as $row)
        <article class="w-full border-b border-slate-200 py-2.5 last:border-b-0">
            <div class="flex w-full flex-col gap-2.5 md:flex-row md:items-start md:gap-4">
                <div class="shrink-0 text-xs leading-5 font-medium text-blue-600 md:w-[12.5rem]">{{ $row['range'] }}</div>
                <p class="min-w-0 flex-1 text-xs leading-5 break-words whitespace-pre-line text-slate-950">{{ $row['text'] }}</p>
            </div>
        </article>
    @endforeach

    @if ($hasFailed)
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-700">Audio upload could not be processed.</p>
            <button type="button" id="retry-upload" hidden
                    class="mt-3 inline-flex h-10 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:bg-blue-50">
                Retry
            </button>
        </div>
    @endif
@else
    <div class="mx-auto flex min-h-full max-w-3xl flex-col items-center justify-center py-16 text-center">
        <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">{{ $emptyPanel['eyebrow'] }}</p>
        <h3 class="mt-4 text-3xl font-semibold text-slate-950">{{ $emptyPanel['title'] }}</h3>
        <p class="mt-4 max-w-xl text-sm leading-6 text-blue-950">{{ $emptyPanel['copy'] }}</p>
    </div>
@endif
