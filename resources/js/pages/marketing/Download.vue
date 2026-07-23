<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Check,
    Cpu,
    Download,
    HardDrive,
    Laptop,
    MonitorDown,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type Release = {
    available: boolean;
    platform: string;
    version: string | null;
    zipfile: string | null;
    size: string | null;
    published_at: string | null;
    download_url: string | null;
};

type DownloadContent = {
    hero: {
        eyebrow: string;
        title: string;
        intro: string;
    };
    download_card: {
        title: string;
        body: string;
        button_label: string;
        empty_label: string;
    };
    requirements: Array<{
        icon: keyof typeof iconMap;
        title: string;
        body: string;
    }>;
    account: {
        title: string;
        body: string;
        bullets: string[];
        button_label: string;
    };
    faq: Array<{
        question: string;
        answer: string;
    }>;
};

const iconMap = {
    Laptop,
    Cpu,
    HardDrive,
    ShieldCheck,
};

const props = defineProps<{
    release: Release;
    content: DownloadContent;
}>();

const requirements = computed(() =>
    props.content.requirements.map((requirement) => ({
        ...requirement,
        icon: iconMap[requirement.icon] ?? Laptop,
    })),
);
</script>

<template>
    <Head title="Download" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    {{ content.hero.eyebrow }}
                </p>
                <h1
                    class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ content.hero.title }}
                </h1>
                <p
                    class="mx-auto mt-5 max-w-3xl text-base leading-7 text-slate-700"
                >
                    {{ content.hero.intro }}
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-xl px-6">
                <div
                    class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                >
                    <MonitorDown class="mx-auto size-10 text-blue-600" />
                    <h2 class="mt-4 text-2xl font-semibold text-slate-950">
                        {{ content.download_card.title }}
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-700">
                        {{ content.download_card.body }}
                    </p>

                    <Button
                        v-if="release.available && release.download_url"
                        as-child
                        size="lg"
                        class="mt-8 w-full"
                    >
                        <a :href="release.download_url">
                            <Download class="size-4" />
                            {{ content.download_card.button_label }}
                        </a>
                    </Button>
                    <Button v-else size="lg" class="mt-8 w-full" disabled>
                        {{ content.download_card.empty_label }}
                    </Button>

                    <div class="mt-4 text-sm text-slate-600">
                        <template v-if="release.available">
                            Version {{ release.version ?? 'unknown' }}
                            <span aria-hidden="true"> | </span>
                            {{ release.size ?? 'unknown size' }}
                            <span aria-hidden="true"> | </span>
                            {{ release.published_at ?? 'unknown date' }}
                        </template>
                        <template v-else>
                            The desktop release is not available yet. Please
                            check back later.
                        </template>
                    </div>

                    <div
                        class="mt-6 flex flex-wrap justify-center gap-4 text-sm"
                    >
                        <a
                            v-if="release.available"
                            class="font-medium text-blue-600 hover:text-blue-700"
                            href="/download/latest?platform=windows"
                        >
                            All releases
                        </a>
                        <span v-else class="font-medium text-slate-600">
                            All releases
                        </span>
                        <a
                            class="font-medium text-blue-600 hover:text-blue-700"
                            href="#requirements"
                        >
                            System requirements
                        </a>
                        <span class="font-medium text-slate-600">
                            Update from within the app
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section id="requirements" class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    System requirements
                </h2>
                <div class="mt-8 grid gap-6 md:grid-cols-2">
                    <div
                        v-for="requirement in requirements"
                        :key="requirement.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="requirement.icon"
                            class="size-5 text-blue-600"
                        />
                        <h3 class="mt-4 text-lg font-semibold text-slate-950">
                            {{ requirement.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ requirement.body }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white px-6 py-16 md:py-24">
            <div
                class="mx-auto max-w-6xl rounded-lg border border-blue-100 bg-blue-50 p-6"
            >
                <div class="grid gap-6 md:grid-cols-[1fr_auto] md:items-center">
                    <div>
                        <h2 class="text-2xl font-semibold text-blue-950">
                            {{ content.account.title }}
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-blue-900">
                            {{ content.account.body }}
                        </p>
                        <div class="mt-5 grid gap-2 text-sm text-blue-900">
                            <div
                                v-for="bullet in content.account.bullets"
                                :key="bullet"
                                class="flex items-start gap-2"
                            >
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span>{{ bullet }}</span>
                            </div>
                        </div>
                    </div>
                    <Button as-child>
                        <Link href="/register">{{
                            content.account.button_label
                        }}</Link>
                    </Button>
                </div>
            </div>
        </section>

        <section class="bg-white px-6 pb-16 md:pb-24">
            <div class="mx-auto max-w-3xl">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    Desktop FAQ
                </h2>
                <div class="mt-8 grid gap-4">
                    <details
                        v-for="(item, index) in content.faq"
                        :key="item.question"
                        class="rounded-lg border border-slate-200 bg-white p-4"
                        :open="index === 0"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            {{ item.question }}
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            {{ item.answer }}
                        </p>
                    </details>
                </div>
            </div>
        </section>
    </main>
</template>
