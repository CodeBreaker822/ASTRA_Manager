<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Image, Save } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type Post = {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    body_markdown: string;
    cover_url?: string | null;
    status: 'draft' | 'published';
    published_at?: string | null;
};

const props = defineProps<{
    post: Post | null;
    previewHtml: string;
}>();

defineOptions({
    layout: DashboardLayout,
});

const form = useForm({
    title: props.post?.title ?? '',
    slug: props.post?.slug ?? '',
    excerpt: props.post?.excerpt ?? '',
    body_markdown: props.post?.body_markdown ?? '',
    cover: null as File | null,
    remove_cover: false,
    status: props.post?.status ?? 'draft',
    published_at: props.post?.published_at ?? '',
});

const previewHtml = ref(props.previewHtml);
const slugTouched = ref(Boolean(props.post?.slug));
const coverName = ref('');
let previewTimer: ReturnType<typeof setTimeout> | null = null;

const isEditing = computed(() => props.post !== null);
const pageTitle = computed(() => (isEditing.value ? 'Edit post' : 'New post'));

const slugify = (value: string) =>
    value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

const csrfToken = () =>
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

const refreshPreview = async () => {
    const response = await fetch('/dashboard/blog/preview', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ body_markdown: form.body_markdown }),
    });

    if (response.ok) {
        const payload = (await response.json()) as { html: string };
        previewHtml.value = payload.html;
    }
};

watch(
    () => form.title,
    (title) => {
        if (!slugTouched.value) {
            form.slug = slugify(title);
        }
    },
);

watch(
    () => form.body_markdown,
    () => {
        if (previewTimer) {
            clearTimeout(previewTimer);
        }

        previewTimer = setTimeout(() => {
            void refreshPreview();
        }, 400);
    },
);

const onCoverChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    form.cover = file;
    coverName.value = file?.name ?? '';
};

const submit = () => {
    const url = isEditing.value
        ? `/dashboard/blog/${props.post?.id}`
        : '/dashboard/blog';

    form.transform((data) => ({
        ...data,
        _method: isEditing.value ? 'put' : undefined,
    })).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => form.transform((data) => data),
    });
};
</script>

<template>
    <Head :title="pageTitle" />

    <form class="space-y-6" @submit.prevent="submit">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Link
                    href="/dashboard/blog"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700"
                >
                    <ArrowLeft class="size-4" />
                    Blog
                </Link>
                <h1 class="mt-3 text-xl font-semibold text-slate-950">
                    {{ pageTitle }}
                </h1>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                <Save v-else class="size-4" />
                Save
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-5">
                <div class="grid gap-2">
                    <Label for="title">Title</Label>
                    <Input id="title" v-model="form.title" class="h-11" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">Slug</Label>
                    <Input
                        id="slug"
                        v-model="form.slug"
                        class="h-11"
                        @input="slugTouched = true"
                    />
                    <InputError :message="form.errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="excerpt">Excerpt</Label>
                    <textarea
                        id="excerpt"
                        v-model="form.excerpt"
                        rows="3"
                        class="min-h-24 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                    <InputError :message="form.errors.excerpt" />
                </div>

                <div class="grid gap-2">
                    <Label for="body">Body</Label>
                    <textarea
                        id="body"
                        v-model="form.body_markdown"
                        rows="18"
                        class="min-h-[420px] rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm leading-6 text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                    <InputError :message="form.errors.body_markdown" />
                </div>
            </div>

            <aside class="space-y-5">
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="grid gap-2">
                        <Label for="status">Status</Label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <Label for="published_at">Published date</Label>
                        <Input
                            id="published_at"
                            v-model="form.published_at"
                            type="datetime-local"
                            class="h-11"
                        />
                        <InputError :message="form.errors.published_at" />
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <Label for="cover">Cover image</Label>
                    <label
                        for="cover"
                        class="mt-3 flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-sm text-slate-600 hover:border-blue-300 hover:bg-blue-50"
                    >
                        <Image class="size-5 text-blue-600" />
                        <span>{{
                            coverName || 'Choose jpg, png, or webp'
                        }}</span>
                    </label>
                    <input
                        id="cover"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="sr-only"
                        @change="onCoverChange"
                    />
                    <InputError :message="form.errors.cover" />

                    <label
                        v-if="post?.cover_url"
                        class="mt-4 flex items-center gap-2 text-sm text-slate-700"
                    >
                        <input
                            v-model="form.remove_cover"
                            type="checkbox"
                            class="size-4"
                        />
                        Remove current cover
                    </label>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="text-sm font-semibold text-slate-950">
                        Preview
                    </div>
                    <div
                        class="mt-3 max-h-[480px] overflow-auto border-t border-slate-200 pt-3 text-sm leading-6 text-slate-700 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mt-5 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:mt-1 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5"
                        v-html="previewHtml"
                    />
                </div>
            </aside>
        </div>
    </form>
</template>
