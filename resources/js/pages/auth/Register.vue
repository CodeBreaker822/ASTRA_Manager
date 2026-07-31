<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    googleAuthAvailable: boolean;
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Start your JERVA Transcriber workspace.',
    },
});

const page = usePage();
const googleError = computed(
    () => (page.props.errors as Record<string, string | undefined>)?.google,
);
</script>

<template>
    <Head title="Register" />

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
        <span>or register with email</span>
        <span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="2"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="4"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-slate-600">
            Already have an account?
            <TextLink :href="login()" :tabindex="5">Log in</TextLink>
        </div>
    </Form>
</template>
