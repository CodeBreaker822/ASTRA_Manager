@include('partials.seo')

{{-- $card is the download_card section, resolved by DownloadController::index(). --}}

<x-layouts.marketing :title="$seo['title']">
    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                    {{ $content['hero']['eyebrow'] }}
                </p>
                <h1 class="mx-auto mt-4 max-w-4xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl">
                    {{ $content['hero']['title'] }}
                </h1>
                <p class="mx-auto mt-5 max-w-3xl text-base leading-7 text-slate-700">{{ $content['hero']['intro'] }}</p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-xl px-6">
                <div class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                    <x-icon name="monitor-down" class="mx-auto size-10 text-blue-600" />
                    <p class="mt-4 text-xs font-semibold tracking-wide text-blue-600 uppercase">{{ $card['badge'] }}</p>
                    <h2 class="mt-3 text-2xl font-semibold text-slate-950">{{ $card['title'] }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-700">{{ $card['body'] }}</p>

                    @if ($release['available'] && $release['download_url'])
                        <x-ui.button :href="$release['download_url']" size="lg" class="mt-8 w-full">
                            <x-icon name="download" />
                            {{ $card['button_label'] }}
                        </x-ui.button>
                    @else
                        <x-ui.button size="lg" class="mt-8 w-full" disabled>{{ $card['empty_label'] }}</x-ui.button>
                    @endif

                    <div class="mt-4 text-sm text-slate-600">
                        @if ($release['available'])
                            {{ $release['platform'] }}
                            <span aria-hidden="true">{{ $card['metadata_separator'] }}</span>
                            {{ $release['size'] ?? $card['size_unavailable_label'] }}
                            <span aria-hidden="true">{{ $card['metadata_separator'] }}</span>
                            {{ $release['published_at'] ?? $card['date_unavailable_label'] }}
                        @else
                            {{ $card['unavailable_body'] }}
                        @endif
                    </div>

                    <div class="mt-6 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm">
                        <a class="font-medium text-blue-600 hover:text-blue-700" href="{{ $card['models_link_url'] }}">
                            {{ $card['models_link_label'] }}
                        </a>
                        <a class="font-medium text-blue-600 hover:text-blue-700" href="{{ $card['requirements_link_url'] }}">
                            {{ $card['requirements_link_label'] }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['benefits_intro']['title'] }}</h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">{{ $content['benefits_intro']['intro'] }}</p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-2">
                    @foreach ($content['benefits'] as $benefit)
                        <article class="rounded-lg border border-slate-200 bg-white p-6">
                            <x-icon :name="$benefit['icon']" fallback="ShieldCheck" class="size-5 text-blue-600" />
                            <h3 class="mt-4 text-lg font-semibold text-slate-950">{{ $benefit['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $benefit['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="models" class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['models']['title'] }}</h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">{{ $content['models']['intro'] }}</p>
                </div>
                <div class="mt-10 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="bg-blue-50 text-blue-950">
                            <tr>
                                <th class="px-5 py-4 font-semibold">{{ $content['models']['name_column'] }}</th>
                                <th class="px-5 py-4 font-semibold">{{ $content['models']['size_column'] }}</th>
                                <th class="px-5 py-4 font-semibold">{{ $content['models']['best_for_column'] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @foreach ($content['models']['items'] as $model)
                                <tr>
                                    <th scope="row" class="px-5 py-4 font-semibold text-slate-950">{{ $model['name'] }}</th>
                                    <td class="px-5 py-4 text-slate-700">{{ $model['size'] }}</td>
                                    <td class="px-5 py-4 leading-6 text-slate-700">{{ $model['best_for'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-5 text-sm leading-6 text-slate-600">{{ $content['models']['note'] }}</p>
            </div>
        </section>

        <section id="requirements" class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['requirements_intro']['title'] }}</h2>
                <div class="mt-8 grid gap-6 md:grid-cols-2">
                    @foreach ($content['requirements'] as $requirement)
                        <article class="rounded-lg border border-slate-200 bg-white p-6">
                            <x-icon :name="$requirement['icon']" fallback="Laptop" class="size-5 text-blue-600" />
                            <h3 class="mt-4 text-lg font-semibold text-slate-950">{{ $requirement['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $requirement['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $content['steps']['title'] }}</h2>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($content['steps']['items'] as $index => $step)
                        <li class="rounded-lg border border-slate-200 bg-white p-6">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">
                                {{ $index + 1 }}
                            </span>
                            <h3 class="mt-5 text-lg font-semibold text-slate-950">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-16 md:py-24">
            <div class="mx-auto max-w-6xl rounded-lg border border-blue-100 bg-blue-50 p-6">
                <div class="grid gap-6 md:grid-cols-[1fr_auto] md:items-center">
                    <div>
                        <h2 class="text-2xl font-semibold text-blue-950">{{ $content['account']['title'] }}</h2>
                        <p class="mt-3 text-sm leading-6 text-blue-900">{{ $content['account']['body'] }}</p>
                        <div class="mt-5 grid gap-2 text-sm text-blue-900">
                            @foreach ($content['account']['bullets'] as $bullet)
                                <div class="flex items-start gap-2">
                                    <x-icon name="check" class="mt-0.5 size-4 text-blue-600" />
                                    <span>{{ $bullet }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.button :href="$content['account']['button_url']">
                        {{ $content['account']['button_label'] }}
                    </x-ui.button>
                </div>
                <x-marketing.pricing-proof :pricing="$pricing" class="mt-8" />
                <p class="mt-4 text-center text-sm text-blue-900">
                    <a href="{{ $content['account']['pricing_link_url'] }}"
                       class="font-semibold text-blue-700 hover:text-blue-950">
                        {{ $content['account']['pricing_link_label'] }}
                    </a>
                    {{ $content['account']['pricing_link_suffix'] }}
                </p>
            </div>
        </section>

        <section class="bg-white px-6 py-16 md:py-24">
            <div class="mx-auto max-w-3xl">
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
