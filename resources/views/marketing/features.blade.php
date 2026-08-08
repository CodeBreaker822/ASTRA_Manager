@include('partials.seo')

<x-layouts.marketing :title="$seo['title']">
    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                    {{ $content['hero']['eyebrow'] }}
                </p>
                <div class="mt-4 grid gap-8 lg:grid-cols-[0.9fr_1fr]">
                    <h1 class="text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl">
                        {{ $content['hero']['title'] }}
                    </h1>
                    <div>
                        <p class="text-base leading-7 text-slate-700">{{ $content['hero']['intro'] }}</p>
                        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                            <x-ui.button :href="$content['hero']['online_button_url']">
                                {{ $content['hero']['online_button_label'] }}
                            </x-ui.button>
                            <x-ui.button :href="$content['hero']['desktop_button_url']" variant="outline">
                                <x-icon name="download" />
                                {{ $content['hero']['desktop_button_label'] }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-10">
            <x-marketing.pricing-proof :pricing="$pricing" class="mx-auto max-w-6xl" />
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-14 px-6">
                @foreach ($content['feature_rows'] as $index => $feature)
                    <article class="grid gap-8 lg:grid-cols-2 lg:items-center">
                        <div @class(['lg:order-2' => $index % 2 === 1])>
                            <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                                {{ $feature['eyebrow'] }}
                            </p>
                            <h2 class="mt-3 text-2xl font-semibold text-slate-950">{{ $feature['title'] }}</h2>
                            <p class="mt-4 text-base leading-7 text-slate-700">{{ $feature['body'] }}</p>
                            <div class="mt-6 grid gap-3">
                                @foreach ($feature['bullets'] as $bullet)
                                    <div class="flex items-start gap-3 text-sm text-slate-700">
                                        <x-icon name="check" class="mt-0.5 size-4 text-blue-600" />
                                        <span>{{ $bullet }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                            <div class="rounded-lg border border-blue-100 bg-blue-50 p-6">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-blue-600 text-white">
                                        <x-icon :name="$feature['icon']" fallback="FileText" class="size-5" />
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-blue-950">{{ $feature['title'] }}</p>
                                        <p class="text-xs text-blue-900">{{ $content['feature_visual']['subtitle'] }}</p>
                                    </div>
                                </div>
                                <div class="mt-7 grid gap-3">
                                    <div class="h-3 rounded-full bg-blue-200"></div>
                                    <div class="h-3 w-5/6 rounded-full bg-blue-200"></div>
                                    <div class="h-3 w-2/3 rounded-full bg-blue-200"></div>
                                </div>
                                <div class="mt-7 flex flex-wrap gap-2 border-t border-blue-100 pt-4">
                                    <span class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900">
                                        <x-icon name="file-text" class="size-3.5" />
                                        {{ $content['feature_visual']['raw_label'] }}
                                    </span>
                                    <span class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900">
                                        <x-icon name="sparkles" class="size-3.5" />
                                        {{ $content['feature_visual']['cleaned_label'] }}
                                    </span>
                                    <span class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900">
                                        <x-icon name="download" class="size-3.5" />
                                        {{ $content['feature_visual']['export_label'] }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">
                        {{ $content['comparison']['title'] }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">{{ $content['comparison']['intro'] }}</p>
                </div>

                <div class="mt-10 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                    <table class="w-full min-w-[700px] text-left text-sm">
                        <thead class="bg-blue-50 text-blue-950">
                            <tr>
                                <th class="px-5 py-4 font-semibold">{{ $content['comparison']['label_column'] }}</th>
                                <th class="px-5 py-4 font-semibold">{{ $content['comparison']['online_column'] }}</th>
                                <th class="px-5 py-4 font-semibold">{{ $content['comparison']['desktop_column'] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($content['comparison']['rows'] as $row)
                                <tr>
                                    <th scope="row" class="px-5 py-4 font-semibold text-slate-950">{{ $row['label'] }}</th>
                                    <td class="px-5 py-4 leading-6 text-slate-700">{{ $row['online'] }}</td>
                                    <td class="px-5 py-4 leading-6 text-slate-700">{{ $row['desktop'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-5 text-sm leading-6 text-slate-600">
                    {{ $content['comparison']['pricing_note'] }}
                    <a href="{{ $content['comparison']['pricing_link_url'] }}"
                       class="font-semibold text-blue-600 hover:text-blue-700">{{ $content['comparison']['pricing_link_label'] }}</a>
                    {{ $content['comparison']['pricing_link_suffix'] }}
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-950">
                    {{ $content['faq_heading']['title'] }}
                </h2>
                <div class="mt-8 grid gap-4">
                    @foreach ($content['faq'] as $index => $item)
                        <details class="rounded-lg border border-slate-200 bg-white p-4" @if ($index === 0) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-slate-950">
                                {{ $item['question'] }}
                            </summary>
                            <p class="mt-3 text-sm leading-6 text-slate-700">{{ $item['answer'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white px-6 pb-16 md:pb-24">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">{{ $content['cta']['title'] }}</h2>
                    <p class="mt-2 text-sm leading-6 text-blue-900">{{ $content['cta']['body'] }}</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <x-ui.button :href="$content['cta']['online_button_url']">
                        {{ $content['cta']['online_button_label'] }}
                    </x-ui.button>
                    <x-ui.button :href="$content['cta']['desktop_button_url']" variant="outline">
                        {{ $content['cta']['desktop_button_label'] }}
                    </x-ui.button>
                </div>
            </div>
        </section>
    </main>
</x-layouts.marketing>
