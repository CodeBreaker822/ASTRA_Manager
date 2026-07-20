<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <AppLayout
        :breadcrumbs="[
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
        ]"
    >
        <main class="px-6 py-6">
            <slot />
        </main>
    </AppLayout>
</template>
