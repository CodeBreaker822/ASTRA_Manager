<script setup lang="ts">
import { Undo2 } from '@lucide/vue';
import { ref } from 'vue';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import { csrfToken } from '@/lib/workspace';
import type { Transcript } from '@/types/workspace';

const props = defineProps<{
    projectId: number;
    transcript: Transcript;
    globallyDisabled: boolean;
}>();

const emit = defineEmits<{
    acting: [active: boolean];
    transcriptUpdated: [transcript: Transcript];
}>();

const toast = useWorkspaceToast();
const isSubmitting = ref(false);

const undoPolish = async () => {
    isSubmitting.value = true;
    emit('acting', true);

    try {
        const response = await fetch(
            `/workspace/${props.projectId}/transcripts/${props.transcript.id}/polish/undo`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            },
        );
        const payload = (await response.json()) as {
            message?: string;
            transcript?: Transcript;
        };

        if (!response.ok || !payload.transcript) {
            toast.error(payload.message ?? 'Polish could not be undone.');

            return;
        }

        emit('transcriptUpdated', payload.transcript);
        toast.success(payload.message ?? 'Polish undone.');
    } catch {
        toast.error('Polish could not be undone.');
    } finally {
        isSubmitting.value = false;
        emit('acting', false);
    }
};
</script>

<template>
    <button
        type="button"
        :disabled="
            globallyDisabled ||
            isSubmitting ||
            transcript.polish_status === 'processing'
        "
        class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        @click="undoPolish"
    >
        <Undo2 class="size-4" />
        {{ isSubmitting ? 'Undoing' : 'Undo' }}
    </button>
</template>
