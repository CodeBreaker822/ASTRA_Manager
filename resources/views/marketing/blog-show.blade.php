@include('partials.seo')

{{-- $article and the posts' reading_time come from BlogController::show(). --}}
<x-layouts.marketing :title="$seo['title']">
    <main>
        <article class="bg-white py-12 md:py-20">
            <div class="mx-auto max-w-3xl px-6">
                <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-600"
                     aria-label="{{ $article['breadcrumb_aria_label'] }}">
                    <a href="{{ $article['home_url'] }}" class="hover:text-blue-600">{{ $article['home_label'] }}</a>
                    <x-icon name="chevron-right" class="size-3.5" />
                    <a href="{{ $article['blog_url'] }}" class="hover:text-blue-600">{{ $article['blog_label'] }}</a>
                    <x-icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-950" aria-current="page">{{ $post['title'] }}</span>
                </nav>

                <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-600">
                    <span class="inline-flex items-center gap-2">
                        <x-icon name="calendar" class="size-4" />
                        <time datetime="{{ $post['date_iso'] }}">{{ $post['date'] }}</time>
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <x-icon name="clock-3" class="size-4" />
                        {{ $post['reading_time'] }}
                    </span>
                    <span>{{ $article['byline'] }}</span>
                </div>

                <h1 class="mt-4 text-4xl leading-tight font-semibold tracking-tight text-slate-950 md:text-5xl">
                    {{ $post['title'] }}
                </h1>
                <p class="mt-5 text-lg leading-8 text-slate-700">{{ $post['excerpt'] }}</p>

                @if (! empty($post['cover_url']))
                    <img src="{{ $post['cover_url'] }}" alt="{{ $post['title'] }}"
                         class="mt-10 aspect-video w-full rounded-xl border border-slate-200 object-cover">
                @endif

                {{-- Post body is sanitised HTML produced by the CMS. --}}
                <div class="mt-10 border-t border-slate-200 pt-10 text-base leading-7 text-slate-700 [&_a]:font-semibold [&_a]:text-blue-600 [&_a]:underline [&_a]:decoration-blue-200 [&_a]:underline-offset-4 hover:[&_a]:text-blue-700 [&_blockquote]:mt-6 [&_blockquote]:border-l-4 [&_blockquote]:border-blue-200 [&_blockquote]:bg-blue-50 [&_blockquote]:px-5 [&_blockquote]:py-3 [&_blockquote]:text-slate-700 [&_h2]:mt-12 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-slate-950 [&_h3]:mt-8 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-slate-950 [&_li]:mt-2 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-5 [&_strong]:font-semibold [&_strong]:text-slate-950 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6">
                    {!! $post['html'] !!}
                </div>

                <div class="mt-12 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
                    <strong>{{ $article['review_title'] }}</strong>
                    {{ $article['review_body'] }}
                </div>

                <div class="mt-10 flex flex-col gap-4 border-t border-slate-200 pt-8 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ $article['all_guides_url'] }}"
                       class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
                        <x-icon name="arrow-left" />
                        {{ $article['all_guides_label'] }}
                    </a>
                    <p class="text-xs text-slate-500">
                        {{ $article['last_updated_label'] }}
                        <time datetime="{{ $post['updated_date_iso'] }}">{{ $post['updated_date'] }}</time>
                    </p>
                </div>
            </div>
        </article>

        @if (count($relatedPosts))
            <section class="border-t border-slate-200 bg-slate-50 py-16">
                <div class="mx-auto max-w-6xl px-6">
                    <h2 class="text-2xl font-semibold text-slate-950">{{ $article['related_title'] }}</h2>
                    <div class="mt-8 grid gap-6 md:grid-cols-3">
                        @foreach ($relatedPosts as $related)
                            <a href="/blog/{{ $related['slug'] }}" class="group rounded-lg border border-slate-200 bg-white p-6">
                                <x-icon name="file-text" class="size-5 text-blue-600" />
                                <h3 class="mt-4 text-lg font-semibold text-slate-950 group-hover:text-blue-700">
                                    {{ $related['title'] }}
                                </h3>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $related['excerpt'] }}</p>
                                <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-blue-600">
                                    {{ $article['related_read_label'] }}
                                    <x-icon name="arrow-right" />
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="bg-white px-6 py-16">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">{{ $content['article_cta']['title'] }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-900">{{ $content['article_cta']['body'] }}</p>
                </div>
                <x-ui.button :href="$content['article_cta']['button_url']">
                    {{ $content['article_cta']['button_label'] }}
                    <x-icon name="arrow-right" />
                </x-ui.button>
            </div>
        </section>
    </main>
</x-layouts.marketing>
