<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            fill="currentColor"
            d="M20 3.25 36.5 12.5v15L20 36.75 3.5 27.5v-15L20 3.25Zm0 4.6L7.5 14.88v10.24L20 32.15l12.5-7.03V14.88L20 7.85Z"
        />
        <path
            fill="currentColor"
            d="M20 11.25 29.25 28h-5.1l-1.35-2.75h-5.65L15.85 28h-5.1L20 11.25Zm0 5.85-2.08 4.35h4.18L20 17.1Z"
        />
        <path
            fill="currentColor"
            d="M19.8 3.8h.4l2.45 4.2h-5.3L19.8 3.8ZM32.35 25.7l3.7 2.08-.2.34-5.42.08 1.92-2.5ZM7.65 25.7l1.92 2.5-5.42-.08-.2-.34 3.7-2.08Z"
        />
    </svg>
</template>
