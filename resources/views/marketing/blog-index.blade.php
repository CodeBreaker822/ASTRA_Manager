@include('partials.seo')

{{-- $featuredPost, $remainingPosts, and each post's reading_time come from BlogController. --}}
<x-layouts.marketing :title="$seo['title']">
    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                    {{ $content['hero']['eyebrow'] }}
                </p>
                <h1 class="mt-4 max-w-4xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl">
                    {{ $content['hero']['title'] }}
                </h1>
                <p class="mt-5 max-w-3xl text-base leading-7 text-slate-700">{{ $content['hero']['intro'] }}</p>
                <div class="mt-7 flex flex-wrap items-center gap-2">
                    <x-icon name="tags" class="mr-1 size-4 text-blue-600" />
                    @foreach ($content['topics'] as $topic)
                        <span class="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                            {{ $topic }}
                        </span>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            @if ($featuredPost)
                <div class="mx-auto max-w-6xl px-6">
                    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">
                        {{ $content['index']['featured_label'] }}
                    </p>
                    <a href="/blog/{{ $featuredPost['slug'] }}"
                       class="group mt-5 grid overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_14px_40px_rgba(15,23,42,0.07)] transition hover:border-blue-200 hover:shadow-[0_18px_48px_rgba(15,23,42,0.11)] lg:grid-cols-[0.9fr_1.1fr]">
                        <div class="flex min-h-64 items-center justify-center border-b border-slate-200 bg-blue-50 lg:border-r lg:border-b-0">
                            @if (! empty($featuredPost['cover_url']))
                                <img src="{{ $featuredPost['cover_url'] }}" alt="{{ $featuredPost['title'] }}"
                                     class="h-full w-full object-cover">
                            @else
                                <x-icon name="file-text" class="size-16 text-blue-600" />
                            @endif
                        </div>
                        <div class="flex flex-col justify-center p-7 md:p-10">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
                                <span class="inline-flex items-center gap-2">
                                    <x-icon name="calendar" class="size-3.5" />
                                    <time datetime="{{ $featuredPost['date_iso'] }}">{{ $featuredPost['date'] }}</time>
                                </span>
                                <span class="inline-flex items-center gap-2">
                                    <x-icon name="clock-3" class="size-3.5" />
                                    {{ $featuredPost['reading_time'] }}
                                </span>
                            </div>
                            <h2 class="mt-4 text-3xl font-semibold tracking-tight text-slate-950 group-hover:text-blue-700">
                                {{ $featuredPost['title'] }}
                            </h2>
                            <p class="mt-4 text-base leading-7 text-slate-700">{{ $featuredPost['excerpt'] }}</p>
                            <span class="mt-7 inline-flex items-center gap-2 text-sm font-semibold text-blue-600">
                                {{ $content['index']['read_label'] }}
                                <x-icon name="arrow-right" />
                            </span>
                        </div>
                    </a>

                    @if ($remainingPosts)
                        <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            @foreach ($remainingPosts as $post)
                                <a href="/blog/{{ $post['slug'] }}"
                                   class="group overflow-hidden rounded-lg border border-slate-200 bg-white transition-shadow hover:shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                                    <div class="flex aspect-video items-center justify-center border-b border-slate-200 bg-blue-50">
                                        @if (! empty($post['cover_url']))
                                            <img src="{{ $post['cover_url'] }}" alt="{{ $post['title'] }}"
                                                 class="h-full w-full object-cover">
                                        @else
                                            <x-icon name="file-text" class="size-10 text-blue-600" />
                                        @endif
                                    </div>
                                    <div class="p-6">
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
                                            <span class="inline-flex items-center gap-2">
                                                <x-icon name="calendar" class="size-3.5" />
                                                <time datetime="{{ $post['date_iso'] }}">{{ $post['date'] }}</time>
                                            </span>
                                            <span class="inline-flex items-center gap-2">
                                                <x-icon name="clock-3" class="size-3.5" />
                                                {{ $post['reading_time'] }}
                                            </span>
                                        </div>
                                        <h2 class="mt-3 text-lg font-semibold text-slate-950 group-hover:text-blue-700">
                                            {{ $post['title'] }}
                                        </h2>
                                        <p class="mt-3 text-sm leading-6 text-slate-600">{{ $post['excerpt'] }}</p>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <div class="mx-auto max-w-3xl px-6 text-center">
                    <x-icon name="file-text" class="mx-auto size-12 text-blue-600" />
                    <h2 class="mt-5 text-2xl font-semibold text-slate-950">{{ $content['index']['empty_title'] }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ $content['index']['empty_body'] }}</p>
                </div>
            @endif
        </section>

        <section class="border-t border-slate-200 bg-slate-50 px-6 py-16">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 md:flex-row md:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-950">{{ $content['cta']['title'] }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-700">{{ $content['cta']['body'] }}</p>
                </div>
                <x-ui.button :href="$content['cta']['button_url']">
                    {{ $content['cta']['button_label'] }}
                    <x-icon name="arrow-right" />
                </x-ui.button>
            </div>
        </section>
    </main>
</x-layouts.marketing>
