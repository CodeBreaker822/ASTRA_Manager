<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Calendar,
    ChevronRight,
    Clock3,
    FileText,
} from '@lucide/vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import type { MarketingSeo } from '@/types/marketing';

type Post = {
    title: string;
    slug: string;
    date: string;
    date_iso: string;
    updated_date: string;
    updated_date_iso: string;
    reading_minutes: number;
    excerpt: string;
    cover_url?: string | null;
    html?: string;
};

const props = defineProps<{
    post: Post & { html: string };
    relatedPosts: Post[];
    seo: MarketingSeo;
    content: {
        article: {
            breadcrumb_aria_label: string;
            home_label: string;
            home_url: string;
            blog_label: string;
            blog_url: string;
            reading_time_template: string;
            byline: string;
            review_title: string;
            review_body: string;
            all_guides_label: string;
            all_guides_url: string;
            last_updated_label: string;
            related_title: string;
            related_read_label: string;
        };
        article_cta: {
            title: string;
            body: string;
            button_label: string;
            button_url: string;
        };
    };
}>();

const readingTime = (minutes: number): string =>
    props.content.article.reading_time_template.replace(
        '{minutes}',
        minutes.toString(),
    );
</script>

<template>
    <SeoHead :seo="seo" />

    <main>
        <article class="bg-white py-12 md:py-20">
            <div class="mx-auto max-w-3xl px-6">
                <nav
                    class="flex flex-wrap items-center gap-2 text-sm text-slate-600"
                    :aria-label="content.article.breadcrumb_aria_label"
                >
                    <Link
                        :href="content.article.home_url"
                        class="hover:text-blue-600"
                        >{{ content.article.home_label }}</Link
                    >
                    <ChevronRight class="size-3.5" />
                    <Link
                        :href="content.article.blog_url"
                        class="hover:text-blue-600"
                        >{{ content.article.blog_label }}</Link
                    >
                    <ChevronRight class="size-3.5" />
                    <span class="text-slate-950" aria-current="page">
                        {{ post.title }}
                    </span>
                </nav>

                <div
                    class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-600"
                >
                    <span class="inline-flex items-center gap-2">
                        <Calendar class="size-4" />
                        <time :datetime="post.date_iso">{{ post.date }}</time>
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <Clock3 class="size-4" />
                        {{ readingTime(post.reading_minutes) }}
                    </span>
                    <span>{{ content.article.byline }}</span>
                </div>

                <h1
                    class="mt-4 text-4xl leading-tight font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ post.title }}
                </h1>
                <p class="mt-5 text-lg leading-8 text-slate-700">
                    {{ post.excerpt }}
                </p>

                <img
                    v-if="post.cover_url"
                    :src="post.cover_url"
                    :alt="post.title"
                    class="mt-10 aspect-video w-full rounded-xl border border-slate-200 object-cover"
                />

                <div
                    class="mt-10 border-t border-slate-200 pt-10 text-base leading-7 text-slate-700 [&_a]:font-semibold [&_a]:text-blue-600 [&_a]:underline [&_a]:decoration-blue-200 [&_a]:underline-offset-4 hover:[&_a]:text-blue-700 [&_blockquote]:mt-6 [&_blockquote]:border-l-4 [&_blockquote]:border-blue-200 [&_blockquote]:bg-blue-50 [&_blockquote]:px-5 [&_blockquote]:py-3 [&_blockquote]:text-slate-700 [&_h2]:mt-12 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-slate-950 [&_h3]:mt-8 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-slate-950 [&_li]:mt-2 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-5 [&_strong]:font-semibold [&_strong]:text-slate-950 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6"
                    v-html="post.html"
                />

                <div
                    class="mt-12 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950"
                >
                    <strong>{{ content.article.review_title }}</strong>
                    {{ content.article.review_body }}
                </div>

                <div
                    class="mt-10 flex flex-col gap-4 border-t border-slate-200 pt-8 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Link
                        :href="content.article.all_guides_url"
                        class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700"
                    >
                        <ArrowLeft class="size-4" />
                        {{ content.article.all_guides_label }}
                    </Link>
                    <p class="text-xs text-slate-500">
                        {{ content.article.last_updated_label }}
                        <time :datetime="post.updated_date_iso">
                            {{ post.updated_date }}
                        </time>
                    </p>
                </div>
            </div>
        </article>

        <section
            v-if="relatedPosts.length"
            class="border-t border-slate-200 bg-slate-50 py-16"
        >
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-2xl font-semibold text-slate-950">
                    {{ content.article.related_title }}
                </h2>
                <div class="mt-8 grid gap-6 md:grid-cols-3">
                    <Link
                        v-for="related in relatedPosts"
                        :key="related.slug"
                        :href="`/blog/${related.slug}`"
                        class="group rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <FileText class="size-5 text-blue-600" />
                        <h3
                            class="mt-4 text-lg font-semibold text-slate-950 group-hover:text-blue-700"
                        >
                            {{ related.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ related.excerpt }}
                        </p>
                        <span
                            class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-blue-600"
                        >
                            {{ content.article.related_read_label }}
                            <ArrowRight class="size-4" />
                        </span>
                    </Link>
                </div>
            </div>
        </section>

        <section class="bg-white px-6 py-16">
            <div
                class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">
                        {{ content.article_cta.title }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-900">
                        {{ content.article_cta.body }}
                    </p>
                </div>
                <Button as-child>
                    <Link :href="content.article_cta.button_url">
                        {{ content.article_cta.button_label }}
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>
        </section>
    </main>
</template>
