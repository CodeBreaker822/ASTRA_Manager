<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CreditCard, Palette, ShieldCheck, UserRound, X } from '@lucide/vue';
import { computed } from 'vue';
import { useSettingsModal } from '@/composables/useSettingsModal';
import type { SettingsTab } from '@/composables/useSettingsModal';
import Appearance from '@/pages/settings/Appearance.vue';
import Billing from '@/pages/settings/Billing.vue';
import Profile from '@/pages/settings/Profile.vue';
import Security from '@/pages/settings/Security.vue';
import type { Passkey } from '@/types/auth';

type Plan = {
    key: string;
    name: string;
    tagline: string;
    price_label: string;
    minutes: number;
    cta: string;
    featured: boolean;
    price_per_second: number;
    polish_characters: number;
    summary_characters: number;
    polish_price_per_character: number;
    summary_price_per_character: number;
    free_polish_uses_per_day: number;
    free_summary_uses_per_day: number;
    features: string[];
};

type Entitlements = {
    plan: {
        key: string;
        name: string;
        minutes: number;
        free_polish_uses_per_day: number;
        free_summary_uses_per_day: number;
        features: Record<string, unknown>;
    };
    usage: {
        period: string;
        minutes_used: number;
        minutes_remaining: number;
        minutes_credit_balance: number;
        seconds_transcribed: number;
        seconds_credit_balance: number;
        polish_count: number;
        summary_count: number;
        free_polish_remaining: number;
        free_summary_remaining: number;
        polish_credit_characters: number;
        summary_credit_characters: number;
    };
};

type SettingsModalProps = {
    tab: SettingsTab;
    profile?: {
        mustVerifyEmail?: boolean;
        status?: string | null;
    };
    security?: {
        canManageTwoFactor?: boolean;
        canManagePasskeys?: boolean;
        passkeys?: Passkey[];
        passwordRules: string;
        twoFactorEnabled?: boolean;
        requiresConfirmation?: boolean;
    } | null;
    billing?: {
        billing: {
            provider: string | null;
            checkout_available: boolean;
            portal_available: boolean;
            paymongo_ready: Record<string, boolean>;
        };
        entitlements: Entitlements;
        plans: Plan[];
    } | null;
};

const page = usePage();
const modal = useSettingsModal();
const settingsModal = computed(
    () => page.props.settingsModal as SettingsModalProps | null | undefined,
);

const navItems: Array<{
    title: string;
    tab: SettingsTab;
    icon: typeof UserRound;
}> = [
    { title: 'Profile', tab: 'profile', icon: UserRound },
    { title: 'Security', tab: 'security', icon: ShieldCheck },
    { title: 'Appearance', tab: 'appearance', icon: Palette },
    { title: 'Billing', tab: 'billing', icon: CreditCard },
];

const close = () => {
    router.get(
        modal.closeHref.value,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};
</script>

<template>
    <div
        v-if="settingsModal"
        class="fixed inset-0 z-50 bg-blue-950/30 p-4 sm:p-6"
        @click.self="close"
    >
        <div
            class="mx-auto flex h-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="settings-modal-title"
        >
            <header
                class="flex h-16 shrink-0 items-center justify-between border-b border-blue-200 px-5"
            >
                <div>
                    <h2
                        id="settings-modal-title"
                        class="text-lg font-semibold text-black"
                    >
                        Settings
                    </h2>
                    <p class="text-sm text-blue-900">
                        Manage your profile and account settings
                    </p>
                </div>
                <button
                    type="button"
                    aria-label="Close settings"
                    class="grid size-10 place-items-center rounded-lg border border-blue-200 text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700"
                    @click="close"
                >
                    <X class="size-4" />
                </button>
            </header>

            <div class="grid min-h-0 flex-1 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <aside
                    class="min-h-0 border-b border-blue-200 bg-white p-3 lg:border-r lg:border-b-0"
                >
                    <nav class="grid gap-1" aria-label="Settings">
                        <Link
                            v-for="item in navItems"
                            :key="item.tab"
                            :href="modal.settingsHref(item.tab)"
                            preserve-scroll
                            preserve-state
                            replace
                            :class="[
                                'flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium text-black transition hover:bg-blue-50 hover:text-blue-700',
                                {
                                    'bg-blue-100 text-blue-800 shadow-[inset_3px_0_0_#2563eb]':
                                        settingsModal.tab === item.tab,
                                },
                            ]"
                        >
                            <component :is="item.icon" class="size-4" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </nav>
                </aside>

                <main
                    class="min-h-0 [scrollbar-gutter:stable] overflow-y-auto px-5 py-5 sm:px-6"
                >
                    <section class="max-w-5xl space-y-10">
                        <Profile v-if="settingsModal.tab === 'profile'" />
                        <Security
                            v-else-if="
                                settingsModal.tab === 'security' &&
                                settingsModal.security
                            "
                            v-bind="settingsModal.security"
                        />
                        <Appearance
                            v-else-if="settingsModal.tab === 'appearance'"
                        />
                        <Billing
                            v-else-if="
                                settingsModal.tab === 'billing' &&
                                settingsModal.billing
                            "
                            v-bind="settingsModal.billing"
                        />
                    </section>
                </main>
            </div>
        </div>
    </div>
</template>
