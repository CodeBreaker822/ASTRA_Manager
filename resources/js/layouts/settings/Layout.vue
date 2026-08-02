<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    CreditCard,
    LogOut,
    Mic,
    Palette,
    ShieldCheck,
    UserRound,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const { isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();
const handleLogout = () => {
    router.flushAll();
};

const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
    {
        title: 'Recording',
        href: '/settings/recording',
        icon: Mic,
    },
    {
        title: 'Billing',
        href: '/settings/billing',
        icon: CreditCard,
    },
]);
</script>

<template>
    <div class="fixed inset-0 z-40 bg-blue-950/30 p-4 sm:p-6">
        <div
            class="mx-auto flex h-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
        >
            <header
                class="flex h-16 shrink-0 items-center justify-between border-b border-blue-200 px-5"
            >
                <div>
                    <h2 class="text-lg font-semibold text-black">Settings</h2>
                    <p class="text-sm text-blue-900">
                        Manage your profile and account settings
                    </p>
                </div>
                <Link
                    :href="
                        page.props.auth.canAccessDashboard
                            ? '/dashboard'
                            : '/workspace'
                    "
                    aria-label="Close settings"
                    class="grid size-10 place-items-center rounded-lg border border-blue-200 text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700"
                >
                    <X class="size-4" />
                </Link>
            </header>

            <div class="grid min-h-0 flex-1 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <aside
                    class="flex min-h-0 flex-col border-b border-blue-200 bg-white p-3 lg:border-r lg:border-b-0"
                >
                    <nav class="grid gap-1" aria-label="Settings">
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :class="[
                                'flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium text-black transition hover:bg-blue-50 hover:text-blue-700',
                                {
                                    'bg-blue-100 text-blue-800 shadow-[inset_3px_0_0_#2563eb]':
                                        isCurrentOrParentUrl(item.href),
                                },
                            ]"
                        >
                            <component :is="item.icon" class="size-4" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </nav>

                    <div class="mt-3 border-t border-blue-200 pt-3 lg:mt-auto">
                        <Link
                            :href="logout()"
                            as="button"
                            class="flex h-10 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-red-700 transition hover:bg-red-50 hover:text-red-800"
                            data-test="settings-logout-button"
                            @click="handleLogout"
                        >
                            <LogOut class="size-4" />
                            <span>Log out</span>
                        </Link>
                    </div>
                </aside>

                <main
                    class="min-h-0 [scrollbar-gutter:stable] overflow-y-auto px-5 py-5 sm:px-6"
                >
                    <section class="max-w-5xl space-y-10">
                        <slot />
                    </section>
                </main>
            </div>
        </div>
    </div>
</template>
