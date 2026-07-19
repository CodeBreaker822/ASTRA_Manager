<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

const navItems = [
    { label: 'Features', href: '/features' },
    { label: 'Price', href: '/price' },
    { label: 'Blog', href: '/blog' },
    { label: 'Download', href: '/download' },
];
</script>

<template>
    <div class="min-h-screen bg-white text-slate-950">
        <header
            class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
                aria-label="Main navigation"
            >
                <Link href="/" class="flex items-center gap-3">
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white"
                    >
                        <AppLogoIcon class="size-6 fill-current" />
                    </span>
                    <span class="text-base font-semibold text-slate-950">
                        JERVA
                    </span>
                </Link>

                <div class="hidden items-center gap-6 md:flex">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="text-sm font-medium text-slate-700 transition-colors hover:text-blue-600"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <div class="hidden items-center gap-3 md:flex">
                    <Button as-child variant="ghost">
                        <Link href="/login">Sign in</Link>
                    </Button>
                    <Button as-child>
                        <Link href="/register">Get started</Link>
                    </Button>
                </div>

                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            class="md:hidden"
                            variant="ghost"
                            size="icon"
                            aria-label="Open navigation"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="bg-white">
                        <SheetHeader>
                            <SheetTitle class="text-left text-slate-950">
                                JERVA
                            </SheetTitle>
                        </SheetHeader>
                        <div class="mt-8 grid gap-3">
                            <Link
                                v-for="item in navItems"
                                :key="item.href"
                                :href="item.href"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-blue-50 hover:text-blue-700"
                            >
                                {{ item.label }}
                            </Link>
                            <div class="mt-4 grid gap-3">
                                <Button as-child variant="outline">
                                    <Link href="/login">Sign in</Link>
                                </Button>
                                <Button as-child>
                                    <Link href="/register">Get started</Link>
                                </Button>
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>
            </nav>
        </header>

        <slot />

        <footer class="border-t border-slate-200 bg-slate-50">
            <div class="mx-auto grid max-w-6xl gap-8 px-6 py-12 md:grid-cols-4">
                <div>
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white"
                        >
                            <AppLogoIcon class="size-6 fill-current" />
                        </span>
                        <span class="text-base font-semibold text-slate-950">
                            JERVA
                        </span>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        Online transcription workspace for clean transcripts,
                        summaries, and exports.
                    </p>
                </div>

                <div>
                    <h2 class="text-sm font-semibold text-slate-950">
                        Product
                    </h2>
                    <div class="mt-4 grid gap-3 text-sm text-slate-600">
                        <Link href="/features" class="hover:text-blue-600">
                            Features
                        </Link>
                        <Link href="/price" class="hover:text-blue-600">
                            Price
                        </Link>
                        <Link href="/download" class="hover:text-blue-600">
                            Desktop app
                        </Link>
                    </div>
                </div>

                <div>
                    <h2 class="text-sm font-semibold text-slate-950">Learn</h2>
                    <div class="mt-4 grid gap-3 text-sm text-slate-600">
                        <Link href="/blog" class="hover:text-blue-600">
                            Blog
                        </Link>
                        <Link href="/login" class="hover:text-blue-600">
                            Sign in
                        </Link>
                        <Link href="/register" class="hover:text-blue-600">
                            Create account
                        </Link>
                    </div>
                </div>

                <div>
                    <h2 class="text-sm font-semibold text-slate-950">
                        Workspace
                    </h2>
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        The web edition is online-only. Offline Whisper, VAD,
                        and diarization stay in the desktop app.
                    </p>
                </div>
            </div>
            <div class="border-t border-slate-200 px-6 py-4">
                <p class="mx-auto max-w-6xl text-sm text-slate-600">
                    Copyright 2026 JERVA. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
