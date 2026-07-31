<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Settings } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import CreateProjectDialog from '@/components/workspace/CreateProjectDialog.vue';
import { useSettingsModal } from '@/composables/useSettingsModal';
import type { Entitlements, Project } from '@/types/workspace';

const props = defineProps<{
    projects: Project[];
    activeProjectId: number | null;
    entitlements: Entitlements;
    userEmail: string;
}>();

const emit = defineEmits<{
    projectCreated: [];
}>();

const { settingsHref } = useSettingsModal();

const formattedCreditBalance = computed(() =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(props.entitlements.usage.wallet_balance_cents / 100),
);
</script>

<template>
    <aside
        class="flex max-h-[48dvh] w-full shrink-0 flex-col border-b border-slate-200 bg-slate-50 lg:max-h-none lg:min-h-0 lg:w-[19rem] lg:border-r lg:border-b-0"
    >
        <div class="border-b border-slate-200 p-4">
            <div class="flex h-[72px] items-center gap-3 px-2">
                <img
                    src="/JervaLogo.png"
                    alt="JERVA Transcriber"
                    class="h-10 w-10 shrink-0 object-contain"
                />
                <div class="min-w-0">
                    <h1 class="text-base font-semibold text-slate-950">
                        JERVA Transcriber
                    </h1>
                </div>
            </div>

            <CreateProjectDialog @created="emit('projectCreated')" />
        </div>

        <div class="flex-1 overflow-y-auto p-4">
            <p
                class="text-xs font-semibold tracking-wide text-slate-600 uppercase"
            >
                Recent
            </p>
            <div class="mt-3 grid gap-2">
                <Link
                    v-for="project in projects"
                    :key="project.id"
                    :href="`/workspace/${project.id}`"
                    class="flex min-h-11 w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm leading-5 transition"
                    :class="
                        activeProjectId === project.id
                            ? 'bg-blue-100 font-semibold text-blue-800 shadow-[inset_3px_0_0_#2563eb]'
                            : 'text-slate-950 hover:bg-blue-50 hover:text-blue-700'
                    "
                >
                    <span class="block truncate font-medium">
                        {{ project.title }}
                    </span>
                </Link>

                <div
                    v-if="projects.length === 0"
                    class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600"
                >
                    No transcripts yet.
                </div>
            </div>
        </div>

        <div class="border-t border-slate-200 p-4">
            <Link
                :href="settingsHref('billing')"
                preserve-scroll
                preserve-state
                replace
                class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-200 bg-white text-left transition-colors hover:border-slate-300 hover:bg-slate-50"
                aria-label="View free minutes and credit balance"
            >
                <div class="min-w-0 px-3 py-2.5">
                    <p class="text-[11px] font-medium text-slate-500">
                        Free Minutes
                    </p>
                    <p class="mt-0.5 truncate text-sm text-slate-950">
                        <span class="font-semibold">
                            {{ entitlements.usage.minutes_remaining }}
                        </span>
                    </p>
                </div>
                <div class="min-w-0 border-l border-slate-200 px-3 py-2.5">
                    <p class="text-[11px] font-medium text-slate-500">Credit</p>
                    <p
                        class="mt-0.5 truncate text-sm font-semibold text-slate-950"
                    >
                        {{ formattedCreditBalance }}
                    </p>
                </div>
            </Link>
            <div class="mt-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-950">
                        {{ userEmail }}
                    </p>
                    <p class="text-xs text-slate-600">Signed in</p>
                </div>
                <Button as-child size="icon" variant="ghost">
                    <Link
                        :href="settingsHref('profile')"
                        preserve-scroll
                        preserve-state
                        replace
                        aria-label="Settings"
                    >
                        <Settings class="size-5" />
                    </Link>
                </Button>
            </div>
        </div>
    </aside>
</template>
