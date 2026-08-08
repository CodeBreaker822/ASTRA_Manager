<x-layouts.auth
    title="Confirm password"
    heading="Confirm password"
    description="This is a secure area of the application. Please confirm your password before continuing."
>
    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf

        <div class="space-y-6">
            <div class="grid gap-2">
                <x-ui.label for="password">Password</x-ui.label>
                <x-ui.password-input
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <x-ui.input-error name="password" />
            </div>

            <div class="flex items-center">
                <x-ui.submit class="w-full" data-test="confirm-password-button">Confirm password</x-ui.submit>
            </div>
        </div>
    </form>
</x-layouts.auth>
