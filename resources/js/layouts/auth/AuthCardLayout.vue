<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-slate-50 p-6 text-slate-950 md:p-10"
    >
        <div class="flex w-full max-w-sm flex-col gap-6">
            <Link
                :href="home()"
                class="flex flex-col items-center gap-3 self-center font-medium"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-600 text-white shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                >
                    <AppLogoIcon class="size-8 fill-current" />
                </div>
                <span class="sr-only">{{ title }}</span>
            </Link>

            <div class="flex flex-col gap-6">
                <Card
                    class="gap-0 rounded-lg border-slate-200 bg-white py-0 shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                >
                    <CardHeader class="px-6 pt-6 pb-0 text-center">
                        <CardTitle class="text-xl font-semibold text-slate-950">
                            {{ title }}
                        </CardTitle>
                        <CardDescription class="text-sm text-slate-600">
                            {{ description }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="px-6 py-6">
                        <slot />
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
