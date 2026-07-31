<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Settings } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useSettingsModal } from '@/composables/useSettingsModal';

defineProps<{
    title: string;
}>();

const { settingsHref } = useSettingsModal();
</script>

<template>
    <header
        class="flex h-[72px] shrink-0 items-center justify-between border-b border-slate-200 px-4 lg:px-6"
    >
        <div class="min-w-0">
            <p
                class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
            >
                Transcript
            </p>
            <h2 class="truncate text-lg font-semibold text-slate-950">
                {{ title }}
            </h2>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <Button as-child variant="outline" size="icon">
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
    </header>
</template>
