@include('partials.seo')

{{-- $planLabels and each plan's rate strings come from MarketingController::price(). --}}
<x-layouts.marketing :title="$seo['title']">
    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                    {{ $content['hero']['eyebrow'] }}
                </p>
                <h1 class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl">
                    {{ $content['hero']['title'] }}
                </h1>
                <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-700">
                    {{ $content['hero']['intro'] }}
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-6 px-6 lg:grid-cols-3">
                @foreach ($plans as $plan)
                    <article @class([
                        'rounded-lg border bg-white p-8',
                        'border-blue-600 shadow-[0_16px_40px_rgba(15,23,42,0.14)] ring-2 ring-blue-100' => $plan['featured'],
                        'border-slate-200' => ! $plan['featured'],
                    ])>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">{{ $plan['name'] }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $plan['tagline'] }}</p>
                            </div>
                            @if ($plan['featured'])
                                <span class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-900">
                                    {{ $planLabels['popular_label'] }}
                                </span>
                            @endif
                        </div>

                        <div class="mt-8 space-y-3">
                            @if ($plan['upload_price_per_hour'])
                                <div class="text-sm">
                                    <span class="font-medium">{{ $planLabels['audio_upload_label'] }}</span>
                                    {{ $plan['upload_rate'] }}
                                </div>
                            @endif
                            @if ($plan['live_price_per_hour'])
                                <div class="text-sm">
                                    <span class="font-medium">{{ $planLabels['live_recording_label'] }}</span>
                                    {{ $plan['live_rate'] }}
                                </div>
                            @endif
                            @if ($plan['polish_price_per_character'])
                                <div class="text-sm">
                                    <span class="font-medium">{{ $planLabels['polishing_label'] }}</span>
                                    {{ $plan['polish_rate'] }}
                                </div>
                            @endif
                            @if ($plan['summary_price_per_character'])
                                <div class="text-sm">
                                    <span class="font-medium">{{ $planLabels['summarization_label'] }}</span>
                                    {{ $plan['summary_rate'] }}
                                </div>
                            @endif
                        </div>

                        <x-ui.button :href="$planLabels['button_url']" class="mt-8 w-full">{{ $plan['cta'] }}</x-ui.button>

                        <div class="mt-8 grid gap-3">
                            @foreach ($plan['features'] as $feature)
                                <div class="flex items-start gap-3 text-sm text-slate-700">
                                    <x-icon name="check" class="mt-0.5 size-4 text-blue-600" />
                                    <span>{{ $feature }}</span>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['comparison']['title'] }}</h2>
                <div class="mt-8 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <div class="grid grid-cols-[1.4fr_repeat(2,1fr)] border-b border-slate-200 bg-slate-50">
                        <div class="p-4 text-sm font-semibold text-slate-950">
                            {{ $content['comparison']['feature_column_label'] }}
                        </div>
                        @foreach ($plans as $plan)
                            <div class="p-4 text-sm font-semibold text-slate-950">{{ $plan['name'] }}</div>
                        @endforeach
                    </div>

                    @foreach ($comparison as $feature => $enabledPlans)
                        <div class="grid grid-cols-[1.4fr_repeat(2,1fr)] border-b border-slate-200 last:border-b-0">
                            <div class="p-4 text-sm text-slate-700">{{ $feature }}</div>
                            @foreach ($plans as $plan)
                                <div class="p-4 text-sm text-slate-700">
                                    @if (in_array($plan['key'], $enabledPlans, true))
                                        <x-icon name="check" class="size-4 text-blue-600"
                                                :aria-label="$content['comparison']['included_label']" />
                                    @else
                                        <span class="text-slate-600"
                                              aria-label="{{ $content['comparison']['not_included_label'] }}">{{ $content['comparison']['not_included_symbol'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['faq_heading']['title'] }}</h2>
                <div class="mt-8 grid gap-4">
                    @foreach ($content['faq'] as $index => $item)
                        <details class="rounded-lg border border-slate-200 bg-white p-4" @if ($index === 0) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-slate-950">{{ $item['question'] }}</summary>
                            <p class="mt-3 text-sm leading-6 text-slate-700">{{ $item['answer'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    </main>
</x-layouts.marketing>
