<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useSettingsModal } from '@/composables/useSettingsModal';

defineProps<{
    message: string;
}>();

const { settingsHref } = useSettingsModal();
</script>

<template>
    <div
        v-if="message"
        class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900"
    >
        <span>{{ message }}</span>
        <Link
            :href="settingsHref('billing')"
            preserve-scroll
            preserve-state
            replace
            class="shrink-0 font-semibold text-blue-700"
        >
            View plans
        </Link>
    </div>
</template>
