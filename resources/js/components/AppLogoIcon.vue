<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    alt?: string;
};

withDefaults(defineProps<Props>(), {
    alt: 'JERVA Transcriber',
});
</script>

<template>
    <img
        src="/JervaLogo.png"
        :alt="alt"
        title="JERVA Transcriber"
        data-image-meta="usejervalogo"
        width="500"
        height="500"
        :class="['object-contain', className]"
        v-bind="$attrs"
    />
</template>
