<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import { csrfToken } from '@/lib/workspace';
import type { Transcript } from '@/types/workspace';

const props = defineProps<{
    projectId: number;
    transcript: Transcript | null;
    globallyDisabled: boolean;
}>();

const emit = defineEmits<{
    acting: [active: boolean];
    queued: [];
    transcriptUpdated: [transcript: Transcript];
    upgrade: [message: string];
}>();

const toast = useWorkspaceToast();
const open = ref(false);
const preset = ref<
    'english' | 'filipino' | 'grammar' | 'translate_fix' | 'custom'
>('grammar');
const customInstruction = ref('');
const error = ref('');
const isSubmitting = ref(false);

const presets = [
    {
        key: 'english',
        label: 'Translate to English',
        instruction:
            'Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
    },
    {
        key: 'filipino',
        label: 'Translate to Filipino',
        instruction:
            'Translate every non-Filipino part of the transcript into clear Filipino. Treat English, Cebuano, Bisaya, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
    },
    {
        key: 'grammar',
        label: 'Fix grammar',
        instruction:
            'Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order.',
    },
    {
        key: 'translate_fix',
        label: 'Translate and fix',
        instruction:
            'Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Preserve meaning, speaker intent, names, titles, numbers, and time order.',
    },
] as const;

const isPolishing = computed(
    () => props.transcript?.polish_status === 'processing',
);

const hasRawTranscript = computed(
    () =>
        Boolean(props.transcript?.raw_text?.trim()) ||
        (props.transcript?.sections.length ?? 0) > 0,
);

const selectPreset = (selected: (typeof presets)[number]) => {
    preset.value = selected.key;
    customInstruction.value = selected.instruction;
    error.value = '';
};

const editCustomInstruction = () => {
    preset.value = 'custom';
    error.value = '';
};

const setActing = (active: boolean) => {
    isSubmitting.value = active;
    emit('acting', active);
};

const polishTranscript = async () => {
    if (!props.transcript) {
        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('No raw transcript is ready to polish yet.');

        return;
    }

    if (customInstruction.value.trim().length < 3) {
        error.value = 'Enter instructions before polishing.';

        return;
    }

    emit('upgrade', '');
    setActing(true);

    try {
        const response = await fetch(
            `/workspace/${props.projectId}/transcripts/${props.transcript.id}/polish`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    preset: preset.value,
                    instruction: customInstruction.value,
                }),
            },
        );
        const payload = (await response.json()) as {
            message?: string;
            transcript?: Transcript;
            upgrade?: boolean;
        };

        if (!response.ok) {
            const message =
                payload.message ?? 'Transcript could not be polished.';

            if (payload.upgrade) {
                emit('upgrade', message);
            } else {
                toast.error(message);
            }

            return;
        }

        if (payload.transcript) {
            emit('transcriptUpdated', payload.transcript);
        }

        open.value = false;
        emit('queued');
    } catch {
        toast.error('Transcript could not be polished.');
    } finally {
        setActing(false);
    }
};

watch(open, (isOpen) => {
    if (isOpen && customInstruction.value.trim() === '') {
        selectPreset(presets[2]);
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                type="button"
                :disabled="
                    !transcript ||
                    globallyDisabled ||
                    isSubmitting ||
                    isPolishing
                "
                class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Sparkles class="size-4" />
                {{ isPolishing ? 'Polishing' : 'Polish' }}
            </button>
        </DialogTrigger>
        <DialogContent class="border-slate-200 bg-white shadow-2xl">
            <DialogHeader>
                <p class="text-xs font-semibold text-blue-600 uppercase">
                    Polish transcript
                </p>
                <DialogTitle>Instructions</DialogTitle>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label>Preset</Label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <Button
                            v-for="item in presets"
                            :key="item.key"
                            type="button"
                            variant="outline"
                            :class="
                                preset === item.key
                                    ? 'border-blue-300 bg-blue-50 text-blue-700'
                                    : ''
                            "
                            @click="selectPreset(item)"
                        >
                            {{ item.label }}
                        </Button>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="custom-polish">Custom instructions</Label>
                    <textarea
                        id="custom-polish"
                        v-model="customInstruction"
                        maxlength="2000"
                        class="min-h-32 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        @input="editCustomInstruction"
                    />
                    <p v-if="error" class="text-sm text-red-700">
                        {{ error }}
                    </p>
                    <p
                        v-if="transcript?.cleaned_text"
                        class="text-sm text-slate-600"
                    >
                        Polishing again starts from the version now displayed.
                        You can undo it afterward.
                    </p>
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="open = false">
                    Cancel
                </Button>
                <Button
                    :disabled="globallyDisabled || isSubmitting || isPolishing"
                    @click="polishTranscript"
                >
                    {{ isPolishing ? 'Polishing' : 'Polish' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
