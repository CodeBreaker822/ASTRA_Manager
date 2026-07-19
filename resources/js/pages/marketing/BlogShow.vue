<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calendar } from '@lucide/vue';

type Post = {
    title: string;
    slug: string;
    date: string;
    excerpt: string;
    html: string;
};

defineProps<{
    post: Post;
}>();
</script>

<template>
    <Head :title="post.title" />

    <main>
        <article class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
                <Link
                    href="/blog"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700"
                >
                    <ArrowLeft class="size-4" />
                    Blog
                </Link>
                <div
                    class="mt-8 flex items-center gap-2 text-sm text-slate-600"
                >
                    <Calendar class="size-4" />
                    <span>{{ post.date }}</span>
                </div>
                <h1
                    class="mt-4 text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ post.title }}
                </h1>
                <p class="mt-5 text-base leading-7 text-slate-700">
                    {{ post.excerpt }}
                </p>

                <div
                    class="mt-10 border-t border-slate-200 pt-10 text-base leading-7 text-slate-700 [&_h2]:mt-10 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:text-slate-950 [&_li]:mt-2 [&_p]:mt-5 [&_strong]:font-semibold [&_strong]:text-slate-950 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6"
                    v-html="post.html"
                />
            </div>
        </article>
    </main>
</template>
