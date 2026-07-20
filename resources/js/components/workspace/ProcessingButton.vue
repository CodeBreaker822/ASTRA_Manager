<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineOptions({
    inheritAttrs: false,
});

defineProps<{
    loading?: boolean;
    disabled?: boolean;
}>();
</script>

<template>
    <Button v-bind="$attrs" :disabled="disabled || loading">
        <span v-if="loading" class="inline-flex items-center">
            <svg
                class="mr-3 -ml-1 h-4 w-4 animate-spin text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
            >
                <circle
                    class="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    stroke-width="4"
                />
                <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                />
            </svg>
            Processing...
        </span>
        <slot v-else />
    </Button>
</template>
