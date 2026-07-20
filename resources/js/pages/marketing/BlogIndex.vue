<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Calendar, FileText } from '@lucide/vue';

type Post = {
    title: string;
    slug: string;
    date: string;
    excerpt: string;
    cover?: string;
    cover_url?: string | null;
};

defineProps<{
    posts: Post[];
}>();
</script>

<template>
    <Head title="Blog" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Blog
                </p>
                <h1
                    class="mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    Notes from the JERVA workspace
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-slate-700">
                    Product notes, transcription workflow ideas, and release
                    updates for the web and desktop editions.
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div
                class="mx-auto grid max-w-6xl gap-6 px-6 md:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="post in posts"
                    :key="post.slug"
                    :href="`/blog/${post.slug}`"
                    class="overflow-hidden rounded-lg border border-slate-200 bg-white transition-shadow hover:shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
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
                            class="flex items-center gap-2 text-xs text-slate-600"
                        >
                            <Calendar class="size-3.5" />
                            <span>{{ post.date }}</span>
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-950">
                            {{ post.title }}
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ post.excerpt }}
                        </p>
                    </div>
                </Link>
            </div>
        </section>
    </main>
</template>
