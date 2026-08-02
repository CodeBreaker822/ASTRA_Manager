<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

const page = usePage();
const site = computed(() => page.props.marketingSite);
const copyright = computed(() =>
    site.value.footer.copyright.replace(
        '{year}',
        new Date().getFullYear().toString(),
    ),
);
</script>

<template>
    <div class="min-h-screen bg-white text-slate-950">
        <header
            class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
                :aria-label="site.navigation.aria_label"
            >
                <Link
                    :href="site.brand.home_url"
                    class="flex items-center gap-3"
                >
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100"
                    >
                        <AppLogoIcon class="size-6" />
                    </span>
                    <span class="text-base font-semibold text-slate-950">
                        {{ site.brand.name }}
                    </span>
                </Link>

                <div class="hidden items-center gap-6 md:flex">
                    <Link
                        v-for="item in site.navigation.items"
                        :key="item.url"
                        :href="item.url"
                        class="text-sm font-medium text-slate-700 transition-colors hover:text-blue-600"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <div class="hidden items-center gap-3 md:flex">
                    <Button as-child variant="ghost">
                        <Link :href="site.navigation.sign_in_url">
                            {{ site.navigation.sign_in_label }}
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link :href="site.navigation.primary_button_url">
                            {{ site.navigation.primary_button_label }}
                        </Link>
                    </Button>
                </div>

                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            class="md:hidden"
                            variant="ghost"
                            size="icon"
                            :aria-label="site.navigation.mobile_open_label"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="bg-white">
                        <SheetHeader>
                            <SheetTitle class="text-left text-slate-950">
                                {{ site.brand.name }}
                            </SheetTitle>
                        </SheetHeader>
                        <div class="mt-8 grid gap-3">
                            <Link
                                v-for="item in site.navigation.items"
                                :key="item.url"
                                :href="item.url"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-blue-50 hover:text-blue-700"
                            >
                                {{ item.label }}
                            </Link>
                            <div class="mt-4 grid gap-3">
                                <Button as-child variant="outline">
                                    <Link :href="site.navigation.sign_in_url">
                                        {{ site.navigation.sign_in_label }}
                                    </Link>
                                </Button>
                                <Button as-child>
                                    <Link
                                        :href="
                                            site.navigation.primary_button_url
                                        "
                                    >
                                        {{
                                            site.navigation.primary_button_label
                                        }}
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>
            </nav>
        </header>

        <slot />

        <footer class="border-t border-slate-200 bg-slate-50">
            <div
                class="mx-auto grid max-w-6xl gap-8 px-6 py-12 md:grid-cols-2 lg:grid-cols-4"
            >
                <div>
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100"
                        >
                            <AppLogoIcon class="size-6" />
                        </span>
                        <span class="text-base font-semibold text-slate-950">
                            {{ site.brand.name }}
                        </span>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        {{ site.footer.brand_body }}
                    </p>
                </div>

                <div v-for="column in site.footer.columns" :key="column.title">
                    <h2 class="text-sm font-semibold text-slate-950">
                        {{ column.title }}
                    </h2>
                    <div class="mt-4 grid gap-3 text-sm text-slate-600">
                        <Link
                            v-for="link in column.links"
                            :key="link.url"
                            :href="link.url"
                            class="hover:text-blue-600"
                        >
                            {{ link.label }}
                        </Link>
                    </div>
                </div>

                <div>
                    <h2 class="text-sm font-semibold text-slate-950">
                        {{ site.footer.workspace_title }}
                    </h2>
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        {{ site.footer.workspace_body }}
                    </p>
                </div>
            </div>
            <div class="border-t border-slate-200 px-6 py-4">
                <p class="mx-auto max-w-6xl text-sm text-slate-600">
                    {{ copyright }}
                </p>
            </div>
        </footer>
    </div>
</template>
