<x-layouts.settings title="Security settings">
    <h1 class="sr-only">Security settings</h1>

    <div class="space-y-6">
        <x-ui.heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <form method="POST" action="{{ route('user-password.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid gap-2">
                <x-ui.label for="current_password">Current password</x-ui.label>
                <x-ui.password-input
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                />
                <x-ui.input-error name="current_password" />
            </div>

            <div class="grid gap-2">
                <x-ui.label for="password">New password</x-ui.label>
                <x-ui.password-input
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="New password"
                    passwordrules="{{ $passwordRules }}"
                />
                <x-ui.input-error name="password" />
            </div>

            <div class="grid gap-2">
                <x-ui.label for="password_confirmation">Confirm password</x-ui.label>
                <x-ui.password-input
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    passwordrules="{{ $passwordRules }}"
                />
                <x-ui.input-error name="password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <x-ui.submit data-test="update-password-button">Save</x-ui.submit>
            </div>
        </form>
    </div>

    @if ($canManageTwoFactor)
        <div class="space-y-6">
            <x-ui.heading
                variant="small"
                title="Two-factor authentication"
                description="Manage your two-factor authentication settings"
            />

            @if (! $twoFactorEnabled && ! $pendingSetup)
                <div class="flex flex-col items-start justify-start space-y-4">
                    <p class="text-sm text-muted-foreground">
                        When you enable two-factor authentication, you will be prompted for a secure pin
                        during login. This pin can be retrieved from a TOTP-supported application on your
                        phone.
                    </p>
                    <form method="POST" action="{{ route('two-factor.enable') }}">
                        @csrf
                        <x-ui.submit>Enable 2FA</x-ui.submit>
                    </form>
                </div>
            @elseif ($pendingSetup)
                <div class="space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-5">
                    <div class="flex items-center gap-3">
                        <x-icon name="scan-line" class="size-5 text-blue-600" />
                        <h3 class="text-sm font-semibold text-slate-950">Finish enabling two-factor authentication</h3>
                    </div>
                    <p class="text-sm leading-6 text-slate-600">
                        Scan the QR code or enter the setup key in your authenticator app, then enter the
                        6-digit code it generates.
                    </p>

                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                        <div class="w-fit rounded-lg border border-slate-200 bg-white p-3">
                            {!! $qrCodeSvg !!}
                        </div>

                        <div class="min-w-0 flex-1 space-y-4">
                            <div x-data="{ copied: false }">
                                <p class="text-xs font-semibold text-slate-800">Setup key</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate rounded bg-white px-2 py-1.5 font-mono text-sm">{{ $setupKey }}</code>
                                    <button
                                        type="button"
                                        aria-label="Copy setup key"
                                        class="grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 bg-white hover:bg-slate-50"
                                        x-on:click="
                                            navigator.clipboard.writeText(@js($setupKey));
                                            copied = true;
                                            setTimeout(() => (copied = false), 2000);
                                        "
                                    >
                                        <x-icon name="copy" x-show="!copied" />
                                        <x-icon name="check" class="text-green-600" x-cloak x-show="copied" />
                                    </button>
                                </div>
                            </div>

                            @if ($requiresConfirmation)
                                <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-3">
                                    @csrf
                                    <div class="grid gap-2">
                                        <x-ui.label for="two-factor-code">Authentication code</x-ui.label>
                                        <x-ui.input
                                            id="two-factor-code"
                                            name="code"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            maxlength="6"
                                            placeholder="000000"
                                            class="font-mono tracking-widest"
                                        />
                                        <x-ui.input-error name="code" />
                                    </div>
                                    <x-ui.submit>Confirm</x-ui.submit>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('two-factor.disable') }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" size="sm">Cancel setup</x-ui.button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                <div class="flex flex-col items-start justify-start space-y-4">
                    <p class="text-sm text-muted-foreground">
                        You will be prompted for a secure, random pin during login, which you can retrieve
                        from the TOTP-supported application on your phone.
                    </p>

                    <form method="POST" action="{{ route('two-factor.disable') }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.submit variant="destructive">Disable 2FA</x-ui.submit>
                    </form>

                    <x-ui.card class="w-full">
                        <div class="-mt-2 mb-4">
                            <h2 class="flex gap-3 text-base leading-none font-semibold">
                                <x-icon name="lock-keyhole" class="size-4" />2FA recovery codes
                            </h2>
                            <p class="mt-1.5 text-sm text-muted-foreground">
                                Recovery codes let you regain access if you lose your 2FA device. Store them
                                in a secure password manager.
                            </p>
                        </div>

                        <div x-data="{ shown: false }">
                            <div class="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between">
                                <x-ui.button type="button" class="w-fit" x-on:click="shown = !shown">
                                    <x-icon name="eye" x-show="!shown" />
                                    <x-icon name="eye-off" x-cloak x-show="shown" />
                                    <span x-text="shown ? 'Hide recovery codes' : 'View recovery codes'">View recovery codes</span>
                                </x-ui.button>

                                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}"
                                      x-cloak x-show="shown">
                                    @csrf
                                    <x-ui.submit variant="secondary">
                                        <x-icon name="refresh-cw" /> Regenerate codes
                                    </x-ui.submit>
                                </form>
                            </div>

                            <div x-cloak x-show="shown" class="mt-3 space-y-3">
                                <div class="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm">
                                    @foreach ($recoveryCodes as $code)
                                        <div>{{ $code }}</div>
                                    @endforeach
                                </div>
                                <p class="text-xs text-muted-foreground select-none">
                                    Each recovery code can be used once to access your account and will be
                                    removed after use. If you need more, click
                                    <span class="font-bold">Regenerate codes</span> above.
                                </p>
                            </div>
                        </div>
                    </x-ui.card>
                </div>
            @endif
        </div>
    @endif
</x-layouts.settings>
