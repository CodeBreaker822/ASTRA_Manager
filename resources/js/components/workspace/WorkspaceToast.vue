<script setup lang="ts">
import { X } from '@lucide/vue';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';

const { toasts, dismiss } = useWorkspaceToast();
</script>

<template>
    <div class="fixed top-5 right-5 z-[80] grid gap-3">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="flex min-w-80 items-center justify-between gap-4 rounded px-6 py-3 text-white shadow-lg transition duration-300"
            :class="[
                toast.type === 'success' ? 'bg-green-500' : 'bg-red-500',
                toast.leaving
                    ? 'translate-x-full opacity-0'
                    : 'translate-x-0 opacity-100',
            ]"
        >
            <p class="text-sm font-medium">{{ toast.message }}</p>
            <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded text-white hover:bg-white/10"
                aria-label="Close"
                @click="dismiss(toast.id)"
            >
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>
