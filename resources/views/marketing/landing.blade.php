@include('partials.seo')

<x-layouts.marketing :title="$seo['title']">
    <main>
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:py-24 lg:grid-cols-[1fr_0.92fr]">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                        {{ $content['hero']['eyebrow'] }}
                    </p>
                    <h1 class="mt-4 text-4xl leading-tight font-semibold tracking-tight text-slate-950 md:text-5xl">
                        {{ $content['hero']['title'] }}
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-7 text-slate-700">
                        {{ $content['hero']['intro'] }}
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <x-ui.button :href="$content['hero']['online_button_url']" size="lg">
                            {{ $content['hero']['online_button_label'] }}
                            <x-icon name="arrow-right" />
                        </x-ui.button>
                        <x-ui.button :href="$content['hero']['desktop_button_url']" size="lg" variant="outline">
                            <x-icon name="download" />
                            {{ $content['hero']['desktop_button_label'] }}
                        </x-ui.button>
                    </div>
                    <p class="mt-5 text-sm leading-6 text-slate-600">
                        {{ $content['hero']['note'] }}
                        <a href="{{ $content['hero']['note_link_url'] }}"
                           class="font-semibold text-blue-600 hover:text-blue-700">
                            {{ $content['hero']['note_link_label'] }}
                        </a>
                    </p>
                </div>

                <x-marketing.workspace-preview :content="$content['workspace_preview']" />
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-10">
            <x-marketing.pricing-proof :pricing="$pricing" class="mx-auto max-w-6xl" />
            <p class="mx-auto mt-4 max-w-6xl text-center text-sm text-slate-600">
                {{ $content['pricing_note']['text'] }}
                <a href="{{ $content['pricing_note']['link_url'] }}"
                   class="font-semibold text-blue-600 hover:text-blue-700">
                    {{ $content['pricing_note']['link_label'] }}
                </a>
            </p>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                        {{ $content['paths_intro']['eyebrow'] }}
                    </p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ $content['paths_intro']['title'] }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ $content['paths_intro']['body'] }}
                    </p>
                </div>

                <div class="mt-10 grid gap-6 lg:grid-cols-2">
                    @foreach ($content['paths'] as $path)
                        <article class="flex flex-col rounded-lg border border-slate-200 bg-white p-6 shadow-[0_12px_32px_rgba(15,23,42,0.06)]">
                            <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                                {{ $path['eyebrow'] }}
                            </p>
                            <h3 class="mt-3 text-2xl font-semibold text-slate-950">{{ $path['title'] }}</h3>
                            <p class="mt-4 text-sm leading-6 text-slate-700">{{ $path['body'] }}</p>
                            <div class="mt-6 grid gap-3">
                                @foreach ($path['bullets'] as $bullet)
                                    <div class="flex items-start gap-3 text-sm text-slate-700">
                                        <x-icon name="check" class="mt-0.5 size-4 text-blue-600" />
                                        <span>{{ $bullet }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <x-ui.button :href="$path['button_url']" class="mt-8 self-start">
                                {{ $path['button_label'] }}
                                <x-icon name="arrow-right" />
                            </x-ui.button>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">
                        {{ $content['workflow']['title'] }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">{{ $content['workflow']['intro'] }}</p>
                </div>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($content['workflow']['steps'] as $index => $step)
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

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">
                        {{ $content['use_cases']['title'] }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">{{ $content['use_cases']['intro'] }}</p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($content['use_cases']['items'] as $index => $item)
                        <article class="rounded-lg border border-slate-200 bg-white p-6">
                            <x-icon :name="config('ui.marketing.landing.use_cases.'.$index, 'mic')" class="size-5 text-blue-600" />
                            <h3 class="mt-4 text-lg font-semibold text-slate-950">{{ $item['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $item['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-blue-950 py-16 text-white md:py-20">
            <div class="mx-auto grid max-w-6xl gap-8 px-6 lg:grid-cols-[0.7fr_1fr] lg:items-center">
                <div class="flex h-24 w-24 items-center justify-center rounded-lg border border-blue-700 bg-blue-900">
                    <x-icon name="scissors" class="size-10 text-blue-200" />
                </div>
                <div>
                    <p class="text-xs font-semibold tracking-wide text-blue-300 uppercase">
                        {{ $content['vad']['eyebrow'] }}
                    </p>
                    <h2 class="mt-3 text-3xl font-semibold">{{ $content['vad']['title'] }}</h2>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-blue-100">{{ $content['vad']['body'] }}</p>
                    <p class="mt-4 text-sm leading-6 text-blue-300">{{ $content['vad']['note'] }}</p>
                </div>
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

        <section class="bg-slate-50 px-6 py-16 md:py-24">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">{{ $content['cta']['title'] }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-900">{{ $content['cta']['body'] }}</p>
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
