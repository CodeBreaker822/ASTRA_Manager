<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

const props = defineProps<{
    pageKey: 'features' | 'download';
    title: string;
    content: Record<string, any>;
}>();

defineOptions({
    layout: DashboardLayout,
});

const form = useForm({
    content: structuredClone(props.content),
});

const isFeatures = computed(() => props.pageKey === 'features');
const updateUrl = computed(() => `/dashboard/pages/${props.pageKey}`);

const submit = () => {
    form.put(updateUrl.value, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`${title} Page Manager`" />

    <form class="space-y-6" @submit.prevent="submit">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">
                    {{ title }}
                </h1>
                <p class="mt-1 text-sm text-slate-700">
                    Manage structured public page sections.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link href="/dashboard/pages/features">Features</Link>
                </Button>
                <Button as-child variant="outline">
                    <Link href="/dashboard/pages/download">Download</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Save
                </Button>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-950">Hero</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="hero-eyebrow">Eyebrow</Label>
                    <Input
                        id="hero-eyebrow"
                        v-model="form.content.hero.eyebrow"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="hero-title">Title</Label>
                    <Input
                        id="hero-title"
                        v-model="form.content.hero.title"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="hero-intro">Intro</Label>
                    <textarea
                        id="hero-intro"
                        v-model="form.content.hero.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
            </div>
        </section>

        <template v-if="isFeatures">
            <section class="grid gap-4">
                <article
                    v-for="(row, index) in form.content.feature_rows"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <div class="grid gap-4 md:grid-cols-2">
                        <Input v-model="row.eyebrow" class="h-11" />
                        <select
                            v-model="row.icon"
                            class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option
                                v-for="icon in [
                                    'Mic',
                                    'FileAudio',
                                    'Languages',
                                    'Sparkles',
                                    'FileSpreadsheet',
                                    'Network',
                                ]"
                                :key="icon"
                                :value="icon"
                            >
                                {{ icon }}
                            </option>
                        </select>
                        <Input v-model="row.title" class="h-11 md:col-span-2" />
                        <textarea
                            v-model="row.body"
                            rows="3"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100 md:col-span-2"
                        />
                        <Input
                            v-for="(_, bulletIndex) in row.bullets"
                            :key="bulletIndex"
                            v-model="row.bullets[bulletIndex]"
                            class="h-10"
                        />
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">CTA</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <Input v-model="form.content.cta.title" class="h-11" />
                    <Input
                        v-model="form.content.cta.button_label"
                        class="h-11"
                    />
                    <textarea
                        v-model="form.content.cta.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100 md:col-span-2"
                    />
                </div>
            </section>
        </template>

        <template v-else>
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Download Card
                </h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <Input
                        v-model="form.content.download_card.title"
                        class="h-11"
                    />
                    <Input
                        v-model="form.content.download_card.button_label"
                        class="h-11"
                    />
                    <Input
                        v-model="form.content.download_card.empty_label"
                        class="h-11"
                    />
                    <textarea
                        v-model="form.content.download_card.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100 md:col-span-2"
                    />
                </div>
            </section>

            <section class="grid gap-4">
                <article
                    v-for="(requirement, index) in form.content.requirements"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <div class="grid gap-4 md:grid-cols-[160px_1fr]">
                        <select
                            v-model="requirement.icon"
                            class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option
                                v-for="icon in [
                                    'Laptop',
                                    'Cpu',
                                    'HardDrive',
                                    'ShieldCheck',
                                ]"
                                :key="icon"
                                :value="icon"
                            >
                                {{ icon }}
                            </option>
                        </select>
                        <Input v-model="requirement.title" class="h-11" />
                        <textarea
                            v-model="requirement.body"
                            rows="3"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100 md:col-span-2"
                        />
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Account Band
                </h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <Input v-model="form.content.account.title" class="h-11" />
                    <Input
                        v-model="form.content.account.button_label"
                        class="h-11"
                    />
                    <textarea
                        v-model="form.content.account.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100 md:col-span-2"
                    />
                    <Input
                        v-for="(_, index) in form.content.account.bullets"
                        :key="index"
                        v-model="form.content.account.bullets[index]"
                        class="h-10"
                    />
                </div>
            </section>

            <section class="grid gap-4">
                <article
                    v-for="(item, index) in form.content.faq"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <Input v-model="item.question" class="h-11" />
                    <textarea
                        v-model="item.answer"
                        rows="3"
                        class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </article>
            </section>
        </template>
    </form>
</template>
