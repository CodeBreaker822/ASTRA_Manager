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

defineProps<{
    release: Release;
}>();

const requirements = [
    {
        icon: Laptop,
        title: 'OS',
        body: 'Windows desktop build for v1 distribution.',
    },
    {
        icon: Cpu,
        title: 'Memory',
        body: '8 GB RAM minimum. More memory helps local offline models.',
    },
    {
        icon: HardDrive,
        title: 'Disk space',
        body: 'Reserve space for the app package and optional offline models.',
    },
    {
        icon: ShieldCheck,
        title: 'Account',
        body: 'Pair with your JERVA account when online features are enabled.',
    },
];
</script>

<template>
    <Head title="Download" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Desktop app
                </p>
                <h1
                    class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    Get JERVA for desktop
                </h1>
                <p
                    class="mx-auto mt-5 max-w-3xl text-base leading-7 text-slate-700"
                >
                    Use JERVA Web for online transcription anywhere. Use the
                    desktop app when you need offline Whisper, local VAD,
                    speaker separation, and files that stay on your machine.
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
                        Download for Windows
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-700">
                        The current desktop distribution channel is Windows-only
                        until additional platform packages are uploaded.
                    </p>

                    <Button
                        v-if="release.available && release.download_url"
                        as-child
                        size="lg"
                        class="mt-8 w-full"
                    >
                        <a :href="release.download_url">
                            <Download class="size-4" />
                            Download for Windows
                        </a>
                    </Button>
                    <Button v-else size="lg" class="mt-8 w-full" disabled>
                        No package uploaded
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
                            Upload a package from the admin API settings page to
                            publish a desktop release.
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
                            Pair with your account
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-blue-900">
                            The desktop app can pair with the same account as
                            the web workspace for online services. Offline
                            transcription still runs on your machine.
                        </p>
                        <div class="mt-5 grid gap-2 text-sm text-blue-900">
                            <div class="flex items-start gap-2">
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span
                                    >Web: online, browser-based, no
                                    install</span
                                >
                            </div>
                            <div class="flex items-start gap-2">
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span
                                    >Desktop: offline models and local
                                    files</span
                                >
                            </div>
                        </div>
                    </div>
                    <Button as-child>
                        <Link href="/register">Create account</Link>
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
                        class="rounded-lg border border-slate-200 bg-white p-4"
                        open
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            Is the desktop app free?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            The download channel can publish the desktop app
                            package. Account and plan rules are handled by the
                            web SaaS layer as billing is added.
                        </p>
                    </details>
                    <details
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            What is different from the web version?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            Web transcription is online-only. Desktop keeps the
                            offline-capable model workflow and local processing
                            features.
                        </p>
                    </details>
                    <details
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            How do offline models work?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            Offline models are downloaded and managed by the
                            desktop application, not by the web workspace.
                        </p>
                    </details>
                </div>
            </div>
        </section>
    </main>
</template>
