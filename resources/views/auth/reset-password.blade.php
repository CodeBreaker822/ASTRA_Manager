<x-layouts.auth
    title="Reset password"
    heading="Reset password"
    description="Please enter your new password below"
>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="grid gap-6">
            <div class="grid gap-2">
                <x-ui.label for="email">Email</x-ui.label>
                <x-ui.input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email', $email) }}"
                    autocomplete="email"
                    class="mt-1 block w-full"
                    readonly
                />
                <x-ui.input-error name="email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <x-ui.label for="password">Password</x-ui.label>
                <x-ui.password-input
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
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
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirm password"
                    passwordrules="{{ $passwordRules }}"
                />
                <x-ui.input-error name="password_confirmation" />
            </div>

            <x-ui.submit class="mt-4 w-full" data-test="reset-password-button">Reset password</x-ui.submit>
        </div>
    </form>
</x-layouts.auth>
