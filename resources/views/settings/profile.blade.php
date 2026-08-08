<x-layouts.settings title="Profile settings">
    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <x-ui.heading variant="small" title="Profile" description="Update your email address" />

        <form method="POST" action="{{ route('profile.update') }}" class="space-y-6">
            @csrf
            @method('PATCH')

            <div class="grid gap-2">
                <x-ui.label for="email">Email address</x-ui.label>
                <x-ui.input
                    id="email"
                    name="email"
                    type="email"
                    class="mt-1 block w-full"
                    value="{{ old('email', $user->email) }}"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <x-ui.input-error name="email" class="mt-2" />
            </div>

            @if ($mustVerifyEmail && ! $user->email_verified_at)
                <div>
                    <p class="-mt-4 text-sm text-muted-foreground">
                        Your email address is unverified.
                    </p>
                    <button type="submit" form="resend-verification"
                            class="text-sm text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current">
                        Click here to re-send the verification email.
                    </button>

                    @if ($status === 'verification-link-sent')
                        <div class="mt-2 text-sm font-medium text-green-600">
                            A new verification link has been sent to your email address.
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center gap-4">
                <x-ui.submit data-test="update-profile-button">Save</x-ui.submit>
            </div>
        </form>

        @if ($mustVerifyEmail && ! $user->email_verified_at)
            <form id="resend-verification" method="POST" action="{{ route('verification.send') }}" class="hidden">
                @csrf
            </form>
        @endif
    </div>

    <div class="space-y-6">
        <x-ui.heading variant="small" title="Delete account" description="Delete your account and all of its resources" />

        <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4">
            <div class="relative space-y-0.5 text-red-600">
                <p class="font-medium">Warning</p>
                <p class="text-sm">Please proceed with caution, this cannot be undone.</p>
            </div>

            <x-ui.dialog :open="$errors->has('password')">
                <x-slot:trigger>
                    <x-ui.button variant="destructive" data-test="delete-user-button">Delete account</x-ui.button>
                </x-slot:trigger>

                <form method="POST" action="{{ route('profile.destroy') }}" class="space-y-6">
                    @csrf
                    @method('DELETE')

                    <div class="space-y-3">
                        <h2 class="text-lg font-semibold">Are you sure you want to delete your account?</h2>
                        <p class="text-sm text-muted-foreground">
                            Once your account is deleted, all of its resources and data will also be
                            permanently deleted. Please enter your password to confirm you would like to
                            permanently delete your account.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <x-ui.label for="password" class="sr-only">Password</x-ui.label>
                        <x-ui.password-input id="password" name="password" placeholder="Password" />
                        <x-ui.input-error name="password" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" data-dialog-close>Cancel</x-ui.button>
                        <x-ui.submit variant="destructive" data-test="confirm-delete-user-button">
                            Delete account
                        </x-ui.submit>
                    </div>
                </form>
            </x-ui.dialog>
        </div>
    </div>
</x-layouts.settings>
