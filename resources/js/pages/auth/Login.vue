<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Sign in to JERVA Transcriber',
        description: 'Use JERVA Transcriber workspace.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    googleAuthAvailable: boolean;
}>();

const page = usePage();
const googleError = computed(
    () => (page.props.errors as Record<string, string | undefined>)?.google,
);
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-center text-sm font-medium text-green-700"
    >
        {{ status }}
    </div>

    <a
        v-if="googleAuthAvailable"
        href="/auth/google/redirect"
        class="flex h-11 w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 transition hover:border-blue-300 hover:bg-blue-50"
    >
        <span
            class="grid size-6 place-items-center rounded-full border border-slate-200 font-bold text-blue-600"
            aria-hidden="true"
        >
            G
        </span>
        Continue with Google
    </a>

    <InputError v-if="googleError" class="mt-3" :message="googleError" />

    <div
        v-if="googleAuthAvailable"
        class="my-5 flex items-center gap-3 text-xs text-slate-500"
    >
        <span class="h-px flex-1 bg-slate-200"></span>
        <span>or use your password</span>
        <span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>
    </Form>

    <div class="mt-6 text-center text-sm text-slate-600">
        New here?
        <TextLink href="/register">Create an account</TextLink>
    </div>
</template>
