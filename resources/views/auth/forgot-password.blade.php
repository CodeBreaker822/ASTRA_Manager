<x-layouts.auth
    title="Forgot password"
    heading="Forgot password"
    description="Enter your email to receive a password reset link"
>
    <x-auth.status :status="$status" />

    <div class="space-y-6">
        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="grid gap-2">
                <x-ui.label for="email">Email address</x-ui.label>
                <x-ui.input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    autocomplete="off"
                    autofocus
                    placeholder="email@example.com"
                />
                <x-ui.input-error name="email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <x-ui.submit class="w-full" data-test="email-password-reset-link-button">
                    Email password reset link
                </x-ui.submit>
            </div>
        </form>

        <div class="space-x-1 text-center text-sm text-slate-600">
            <span>Or, return to</span>
            <x-ui.text-link :href="route('login')">log in</x-ui.text-link>
        </div>
    </div>
</x-layouts.auth>
