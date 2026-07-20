<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="" />

    <main
        class="flex min-h-screen items-center justify-center bg-background px-6 py-12 text-foreground"
    >
        <section
            class="w-full max-w-sm rounded-lg border border-border bg-card p-6 shadow-sm"
            aria-labelledby="portal-title"
        >
            <div class="mb-6 flex flex-col items-center text-center">
                <div
                    class="mb-4 flex size-12 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-7 fill-current" />
                </div>

                <h1 id="portal-title" class="text-xl font-semibold">JERVA</h1>
                <p class="mt-1 text-sm text-muted-foreground">Portal</p>
            </div>

            <Link
                :href="$page.props.auth.user ? dashboard() : login()"
                class="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                {{ $page.props.auth.user ? 'Open Dashboard' : 'Log in' }}
            </Link>
        </section>
    </main>
</template>
