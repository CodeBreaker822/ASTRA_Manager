@props(['content'])

<div class="rounded-lg border border-slate-200 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
     aria-label="{{ $content['aria_label'] }}">
    <div class="grid min-h-[32rem] overflow-hidden rounded-lg md:grid-cols-[13rem_1fr]">
        <aside class="border-b border-slate-200 bg-slate-50 p-4 md:border-r md:border-b-0">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100">
                    <x-ui.logo class="size-6" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-slate-950">{{ $content['brand_name'] }}</p>
                    <p class="text-xs text-slate-600">{{ $content['workspace_label'] }}</p>
                </div>
            </div>

            <button class="mt-6 flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">
                <x-icon name="file-headphone" />
                {{ $content['add_transcript_label'] }}
            </button>

            <p class="mt-6 text-xs font-semibold tracking-wide text-slate-600 uppercase">
                {{ $content['recent_label'] }}
            </p>
            <div class="mt-3 grid gap-2">
                @foreach ($content['recent_items'] as $index => $item)
                    <div @class([
                        'rounded-lg px-3 py-2 text-sm',
                        'bg-blue-100 font-medium text-blue-900 shadow-[inset_3px_0_0_#2563eb]' => $index === 0,
                        'text-slate-700' => $index !== 0,
                    ])>{{ $item }}</div>
                @endforeach
            </div>
        </aside>

        <section class="flex flex-col">
            <header class="flex h-[72px] items-center justify-between border-b border-slate-200 px-6">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                        {{ $content['transcript_label'] }}
                    </p>
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ $content['active_transcript_title'] }}
                    </h2>
                </div>
                <x-icon name="sparkles" class="size-5 text-blue-600" />
            </header>

            <div class="mx-auto w-full max-w-3xl flex-1 px-8 py-6">
                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4">
                    <p class="text-sm font-semibold text-blue-950">{{ $content['processing_label'] }}</p>
                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-blue-100">
                        <div class="h-full w-full animate-pulse bg-blue-600"></div>
                    </div>
                </div>
                <div class="mt-6 grid gap-4">
                    @foreach ($content['sample_transcript'] as $sample)
                        <article class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="text-sm leading-6 text-slate-700">{{ $sample }}</p>
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="border-t border-slate-200 px-6 py-4">
                <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-[0_12px_32px_rgba(15,23,42,0.1)]">
                    @foreach ($content['actions'] as $index => $action)
                        <x-ui.button size="sm" :variant="config('ui.marketing.workspace_preview.action_variants.'.$index, 'ghost')">
                            @if (config('ui.marketing.workspace_preview.actions.'.$index))
                                <x-icon :name="config('ui.marketing.workspace_preview.actions.'.$index)" />
                            @endif
                            {{ $action }}
                        </x-ui.button>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</div>
