<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { FileText, Newspaper, Tags } from '@lucide/vue';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

defineOptions({
    layout: DashboardLayout,
});

const page = usePage();

const cards = [
    {
        title: 'Blog',
        body: 'Create, edit, publish, and organize public posts.',
        href: '/dashboard/blog',
        icon: Newspaper,
        visible: () => page.props.auth.canManageBlog,
    },
    {
        title: 'Pricing',
        body: 'Manage plan copy, feature bullets, and comparison rows.',
        href: '/dashboard/pricing',
        icon: Tags,
        visible: () => page.props.auth.canManagePricing,
    },
    {
        title: 'Pages',
        body: 'Edit structured Features and Download page content.',
        href: '/dashboard/pages/features',
        icon: FileText,
        visible: () => page.props.auth.canManagePages,
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="grid gap-4 md:grid-cols-3">
        <Link
            v-for="card in cards.filter((item) => item.visible())"
            :key="card.title"
            :href="card.href"
            class="rounded-lg border border-slate-200 bg-white p-5 transition hover:border-blue-200 hover:bg-blue-50"
        >
            <component :is="card.icon" class="size-5 text-blue-600" />
            <h2 class="mt-4 text-base font-semibold text-slate-950">
                {{ card.title }}
            </h2>
            <p class="mt-2 text-sm leading-6 text-slate-700">
                {{ card.body }}
            </p>
        </Link>
    </div>
</template>
