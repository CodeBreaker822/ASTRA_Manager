<script setup lang="ts">
import { ListChecks, X } from '@lucide/vue';
import { nextTick, onUnmounted, ref, useTemplateRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { Transcript } from '@/types/workspace';

defineProps<{
    projectTitle: string;
    transcript: Transcript | null;
}>();

const open = ref(false);
const trigger = useTemplateRef<HTMLButtonElement>('trigger');
const panel = useTemplateRef<HTMLElement>('panel');

const openLog = async () => {
    open.value = true;
    await nextTick();
    panel.value?.focus();
};

const closeLog = () => {
    open.value = false;
    trigger.value?.focus();
};

watch(open, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <button
        ref="trigger"
        type="button"
        :disabled="!transcript"
        aria-label="Processing log"
        title="Processing log"
        class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        @click="openLog"
    >
        <ListChecks class="size-4" />
    </button>

    <div
        class="fixed inset-0 z-50 bg-slate-950/40 transition-opacity"
        :class="open ? 'opacity-100' : 'pointer-events-none opacity-0'"
        @click="closeLog"
    />
    <aside
        ref="panel"
        class="fixed top-0 right-0 z-50 h-full w-full max-w-sm border-l border-slate-200 bg-white shadow-2xl transition duration-300"
        :class="open ? 'translate-x-0' : 'translate-x-full'"
        aria-label="Processing log"
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        @keydown.esc="closeLog"
    >
        <header
            class="flex h-[72px] items-center justify-between border-b border-slate-200 px-6"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Log
                </p>
                <h2 class="text-lg font-semibold text-slate-950">
                    Processing log
                </h2>
            </div>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Close processing log"
                @click="closeLog"
            >
                <X class="size-5" />
            </Button>
        </header>
        <div class="grid gap-3 p-6">
            <article
                v-for="entry in transcript?.processing_log ?? []"
                :key="`${entry.status}-${entry.created_at}`"
                class="rounded-lg border border-slate-200 bg-white p-4"
            >
                <p class="text-sm font-semibold text-slate-950">
                    {{ entry.status }}
                </p>
                <p class="mt-1 text-sm leading-6 text-slate-700">
                    {{ entry.message }}
                </p>
                <p class="mt-2 text-xs text-slate-600">
                    {{ entry.created_at }}
                </p>
            </article>
            <div
                v-if="(transcript?.processing_log ?? []).length === 0"
                class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900"
            >
                No processing logs found for {{ projectTitle }}.
            </div>
        </div>
    </aside>
</template>
