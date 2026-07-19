<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="font-medium text-blue-600 underline underline-offset-4 transition-colors duration-300 ease-out hover:text-blue-700 hover:decoration-current!"
    >
        <slot />
    </Link>
</template>
