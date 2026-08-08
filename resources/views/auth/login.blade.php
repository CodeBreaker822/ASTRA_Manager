<x-layouts.auth
    title="Log in"
    heading="Sign in to JERVA Transcriber"
    description="Use JERVA Transcriber workspace."
>
    <x-auth.status :status="$status" />

    @if ($googleAuthAvailable)
        <x-auth.google-button divider="or use your password" />
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
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
                <div class="flex items-center justify-between">
                    <x-ui.label for="password">Password</x-ui.label>
                    @if ($canResetPassword)
                        <x-ui.text-link :href="route('password.request')" class="text-sm" tabindex="5">
                            Forgot your password?
                        </x-ui.text-link>
                    @endif
                </div>
                <x-ui.password-input
                    id="password"
                    name="password"
                    required
                    tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <x-ui.input-error name="password" />
            </div>

            <div class="flex items-center justify-between">
                <x-ui.label for="remember" class="flex items-center space-x-3">
                    <x-ui.checkbox name="remember" id="remember" tabindex="3" />
                    <span>Remember me</span>
                </x-ui.label>
            </div>

            <x-ui.submit class="mt-2 w-full" tabindex="4" data-test="login-button">Log in</x-ui.submit>
        </div>
    </form>

    <div class="mt-6 text-center text-sm text-slate-600">
        New here?
        <x-ui.text-link href="/register">Create an account</x-ui.text-link>
    </div>
</x-layouts.auth>
