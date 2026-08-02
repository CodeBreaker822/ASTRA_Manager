<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Calendar, Clock3, FileText, Tags } from '@lucide/vue';
import { computed } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import type { MarketingSeo } from '@/types/marketing';

type Post = {
    title: string;
    slug: string;
    date: string;
    date_iso: string;
    reading_minutes: number;
    excerpt: string;
    cover?: string;
    cover_url?: string | null;
};

type BlogContent = {
    seo: {
        title: string;
        description: string;
    };
    hero: {
        eyebrow: string;
        title: string;
        intro: string;
    };
    topics: string[];
    index: {
        featured_label: string;
        reading_time_template: string;
        read_label: string;
        empty_title: string;
        empty_body: string;
    };
    cta: {
        title: string;
        body: string;
        button_label: string;
        button_url: string;
    };
};

const props = defineProps<{
    content: BlogContent;
    posts: Post[];
    seo: MarketingSeo;
}>();

const featuredPost = computed(() => props.posts[0] ?? null);
const remainingPosts = computed(() => props.posts.slice(1));
const readingTime = (minutes: number): string =>
    props.content.index.reading_time_template.replace(
        '{minutes}',
        minutes.toString(),
    );
</script>

<template>
    <SeoHead :seo="seo" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    {{ content.hero.eyebrow }}
                </p>
                <h1
                    class="mt-4 max-w-4xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ content.hero.title }}
                </h1>
                <p class="mt-5 max-w-3xl text-base leading-7 text-slate-700">
                    {{ content.hero.intro }}
                </p>
                <div class="mt-7 flex flex-wrap items-center gap-2">
                    <Tags class="mr-1 size-4 text-blue-600" />
                    <span
                        v-for="topic in content.topics"
                        :key="topic"
                        class="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                    >
                        {{ topic }}
                    </span>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div v-if="featuredPost" class="mx-auto max-w-6xl px-6">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    {{ content.index.featured_label }}
                </p>
                <Link
                    :href="`/blog/${featuredPost.slug}`"
                    class="group mt-5 grid overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_14px_40px_rgba(15,23,42,0.07)] transition hover:border-blue-200 hover:shadow-[0_18px_48px_rgba(15,23,42,0.11)] lg:grid-cols-[0.9fr_1.1fr]"
                >
                    <div
                        class="flex min-h-64 items-center justify-center border-b border-slate-200 bg-blue-50 lg:border-r lg:border-b-0"
                    >
                        <img
                            v-if="featuredPost.cover_url"
                            :src="featuredPost.cover_url"
                            :alt="featuredPost.title"
                            class="h-full w-full object-cover"
                        />
                        <FileText v-else class="size-16 text-blue-600" />
                    </div>
                    <div class="flex flex-col justify-center p-7 md:p-10">
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600"
                        >
                            <span class="inline-flex items-center gap-2">
                                <Calendar class="size-3.5" />
                                <time :datetime="featuredPost.date_iso">
                                    {{ featuredPost.date }}
                                </time>
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <Clock3 class="size-3.5" />
                                {{ readingTime(featuredPost.reading_minutes) }}
                            </span>
                        </div>
                        <h2
                            class="mt-4 text-3xl font-semibold tracking-tight text-slate-950 group-hover:text-blue-700"
                        >
                            {{ featuredPost.title }}
                        </h2>
                        <p class="mt-4 text-base leading-7 text-slate-700">
                            {{ featuredPost.excerpt }}
                        </p>
                        <span
                            class="mt-7 inline-flex items-center gap-2 text-sm font-semibold text-blue-600"
                        >
                            {{ content.index.read_label }}
                            <ArrowRight class="size-4" />
                        </span>
                    </div>
                </Link>

                <div
                    v-if="remainingPosts.length"
                    class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3"
                >
                    <Link
                        v-for="post in remainingPosts"
                        :key="post.slug"
                        :href="`/blog/${post.slug}`"
                        class="group overflow-hidden rounded-lg border border-slate-200 bg-white transition-shadow hover:shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                    >
                        <div
                            class="flex aspect-video items-center justify-center border-b border-slate-200 bg-blue-50"
                        >
                            <img
                                v-if="post.cover_url"
                                :src="post.cover_url"
                                :alt="post.title"
                                class="h-full w-full object-cover"
                            />
                            <FileText v-else class="size-10 text-blue-600" />
                        </div>
                        <div class="p-6">
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <Calendar class="size-3.5" />
                                    <time :datetime="post.date_iso">
                                        {{ post.date }}
                                    </time>
                                </span>
                                <span class="inline-flex items-center gap-2">
                                    <Clock3 class="size-3.5" />
                                    {{ readingTime(post.reading_minutes) }}
                                </span>
                            </div>
                            <h2
                                class="mt-3 text-lg font-semibold text-slate-950 group-hover:text-blue-700"
                            >
                                {{ post.title }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ post.excerpt }}
                            </p>
                        </div>
                    </Link>
                </div>
            </div>

            <div v-else class="mx-auto max-w-3xl px-6 text-center">
                <FileText class="mx-auto size-12 text-blue-600" />
                <h2 class="mt-5 text-2xl font-semibold text-slate-950">
                    {{ content.index.empty_title }}
                </h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    {{ content.index.empty_body }}
                </p>
            </div>
        </section>

        <section class="border-t border-slate-200 bg-slate-50 px-6 py-16">
            <div
                class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="text-2xl font-semibold text-slate-950">
                        {{ content.cta.title }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-700">
                        {{ content.cta.body }}
                    </p>
                </div>
                <Button as-child>
                    <Link :href="content.cta.button_url">
                        {{ content.cta.button_label }}
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>
        </section>
    </main>
</template>
