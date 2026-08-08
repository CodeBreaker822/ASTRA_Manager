<x-layouts.auth
    title="Register"
    heading="Create an account"
    description="Start your JERVA Transcriber workspace."
>
    @if ($googleAuthAvailable)
        <x-auth.google-button divider="or register with email" />
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
        @csrf

        <div class="grid gap-6">
            <div class="grid gap-2">
                <x-ui.label for="email">Email address</x-ui.label>
                <x-ui.input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <x-ui.input-error name="email" />
            </div>

            <div class="grid gap-2">
                <x-ui.label for="password">Password</x-ui.label>
                <x-ui.password-input
                    id="password"
                    name="password"
                    required
                    tabindex="2"
                    autocomplete="new-password"
                    placeholder="Password"
                    passwordrules="{{ $passwordRules }}"
                />
                <x-ui.input-error name="password" />
            </div>

            <div class="grid gap-2">
                <x-ui.label for="password_confirmation">Confirm password</x-ui.label>
                <x-ui.password-input
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    tabindex="3"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    passwordrules="{{ $passwordRules }}"
                />
                <x-ui.input-error name="password_confirmation" />
            </div>

            <x-ui.submit class="mt-2 w-full" tabindex="4" data-test="register-user-button">
                Create account
            </x-ui.submit>
        </div>

        <div class="text-center text-sm text-slate-600">
            Already have an account?
            <x-ui.text-link :href="route('login')" tabindex="5">Log in</x-ui.text-link>
        </div>
    </form>
</x-layouts.auth>
