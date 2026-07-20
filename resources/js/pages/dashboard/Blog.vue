<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

defineOptions({
    layout: DashboardLayout,
});

type Post = {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    status: 'draft' | 'published';
    date: string;
    author?: string | null;
};

defineProps<{
    posts: Post[];
}>();

const togglePublish = (post: Post) => {
    router.post(
        `/dashboard/blog/${post.id}/publish`,
        {},
        { preserveScroll: true },
    );
};

const deletePost = (post: Post) => {
    if (!window.confirm(`Delete "${post.title}"?`)) {
        return;
    }

    router.delete(`/dashboard/blog/${post.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Blog Manager" />

    <div class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">Blog</h1>
                <p class="mt-1 text-sm text-slate-700">
                    Manage public posts, drafts, and publish status.
                </p>
            </div>
            <Button as-child>
                <Link href="/dashboard/blog/create">
                    <Plus class="size-4" />
                    New post
                </Link>
            </Button>
        </div>

        <div
            v-if="posts.length === 0"
            class="rounded-lg border border-slate-200 bg-white p-6"
        >
            <p class="text-sm text-slate-700">
                No posts are ready for editing yet.
            </p>
        </div>

        <div
            v-else
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
        >
            <div
                class="hidden grid-cols-[1fr_120px_120px_140px_190px] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-600 lg:grid"
            >
                <div>Title</div>
                <div>Status</div>
                <div>Date</div>
                <div>Author</div>
                <div class="text-right">Actions</div>
            </div>

            <div
                v-for="post in posts"
                :key="post.id"
                class="grid gap-3 border-b border-slate-200 px-4 py-4 last:border-b-0 lg:grid-cols-[1fr_120px_120px_140px_190px] lg:items-center lg:gap-4"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium text-slate-950">
                        {{ post.title }}
                    </div>
                    <div class="truncate text-sm text-slate-600">
                        /blog/{{ post.slug }}
                    </div>
                </div>

                <div>
                    <Badge
                        :class="
                            post.status === 'published'
                                ? 'bg-blue-100 text-blue-800 hover:bg-blue-100'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-100'
                        "
                    >
                        {{ post.status }}
                    </Badge>
                </div>

                <div class="text-sm text-slate-700">
                    {{ post.date || 'Draft' }}
                </div>

                <div class="truncate text-sm text-slate-700">
                    {{ post.author || 'Unknown' }}
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="`/dashboard/blog/${post.id}/edit`"
                            >Edit</Link
                        >
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="togglePublish(post)"
                    >
                        {{
                            post.status === 'published'
                                ? 'Unpublish'
                                : 'Publish'
                        }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        @click="deletePost(post)"
                    >
                        Delete
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
