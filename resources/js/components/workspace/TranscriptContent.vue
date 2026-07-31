<script setup lang="ts">
import { Button } from '@/components/ui/button';
import UpgradeBanner from '@/components/workspace/UpgradeBanner.vue';
import type {
    ActiveProject,
    EmptyWorkspacePanel,
    TranscriptRow,
} from '@/types/workspace';

defineProps<{
    project: ActiveProject | null;
    rows: TranscriptRow[];
    emptyPanel: EmptyWorkspacePanel;
    canRetry: boolean;
    upgradeMessage: string;
}>();

defineEmits<{
    retry: [];
}>();
</script>

<template>
    <div class="min-h-0 flex-1 overflow-hidden">
        <div
            class="h-full w-full [scrollbar-gutter:stable] overflow-y-auto px-4 pt-6 pb-40 lg:px-8 lg:pb-32"
        >
            <UpgradeBanner :message="upgradeMessage" />

            <template v-if="rows.length > 0 && project">
                <article
                    v-for="row in rows"
                    :key="row.id"
                    class="w-full border-b border-slate-200 py-2.5 last:border-b-0"
                >
                    <div
                        class="flex w-full flex-col gap-2.5 md:flex-row md:items-start md:gap-4"
                    >
                        <div
                            class="shrink-0 text-xs leading-5 font-medium text-blue-600 md:w-[12.5rem]"
                        >
                            {{ row.range }}
                        </div>
                        <p
                            class="min-w-0 flex-1 text-xs leading-5 break-words whitespace-pre-line text-slate-950"
                        >
                            {{ row.text }}
                        </p>
                    </div>
                </article>
                <div
                    v-if="
                        project.transcripts.some(
                            (transcript) => transcript.status === 'failed',
                        )
                    "
                    class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4"
                >
                    <p class="text-sm font-semibold text-red-700">
                        Audio upload could not be processed.
                    </p>
                    <Button
                        v-if="canRetry"
                        class="mt-3 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                        variant="outline"
                        @click="$emit('retry')"
                    >
                        Retry
                    </Button>
                </div>
            </template>

            <div
                v-else
                class="mx-auto flex min-h-full max-w-3xl flex-col items-center justify-center py-16 text-center"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    {{ emptyPanel.eyebrow }}
                </p>
                <h3 class="mt-4 text-3xl font-semibold text-slate-950">
                    {{ emptyPanel.title }}
                </h3>
                <p class="mt-4 max-w-xl text-sm leading-6 text-blue-950">
                    {{ emptyPanel.copy }}
                </p>
            </div>
        </div>
    </div>
</template>
