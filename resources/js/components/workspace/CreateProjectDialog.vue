<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ProcessingButton from '@/components/workspace/ProcessingButton.vue';

const emit = defineEmits<{
    created: [];
}>();

const open = ref(false);
const form = useForm({
    title: '',
});

const createProject = () => {
    form.post('/workspace', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
            emit('created');
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                type="button"
                class="mt-5 flex h-11 w-full cursor-pointer items-center justify-center rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white transition hover:bg-blue-700"
            >
                Add Transcript
            </button>
        </DialogTrigger>
        <DialogContent
            class="max-w-md border-slate-200 bg-white p-4 shadow-2xl"
            :show-close-button="false"
        >
            <DialogHeader>
                <DialogTitle class="text-base font-semibold text-slate-950">
                    Add Transcript
                </DialogTitle>
            </DialogHeader>
            <form class="mt-4 grid gap-4" @submit.prevent="createProject">
                <div class="grid gap-2">
                    <Label for="project-title">Transcript name</Label>
                    <Input
                        id="project-title"
                        v-model="form.title"
                        autofocus
                        placeholder="Project or conversation name"
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <DialogFooter class="gap-2">
                    <button
                        type="button"
                        class="h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="open = false"
                    >
                        Cancel
                    </button>
                    <ProcessingButton
                        type="submit"
                        :loading="form.processing"
                        class="h-10 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700"
                    >
                        Add
                    </ProcessingButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
