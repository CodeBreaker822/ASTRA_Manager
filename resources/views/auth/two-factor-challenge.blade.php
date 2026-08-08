{{-- $startInRecovery is composed on by AppServiceProvider. --}}
<x-layouts.auth
    title="Two-factor authentication"
    x-data="{
        recovery: {{ $startInRecovery ? 'true' : 'false' }},
        code: '',
        get heading() {
            return this.recovery ? 'Recovery code' : 'Authentication code';
        },
        get description() {
            return this.recovery
                ? 'Please confirm access to your account by entering one of your emergency recovery codes.'
                : 'Enter the authentication code provided by your authenticator application.';
        },
        get toggleText() {
            return this.recovery
                ? 'login using an authentication code'
                : 'login using a recovery code';
        },
        toggle() {
            this.recovery = !this.recovery;
            this.code = '';
            $el.querySelectorAll('[data-otp-slot]').forEach((slot) => (slot.value = ''));
        },
        syncFromSlots(slot) {
            this.code = [...slot.closest('[data-otp-group]').querySelectorAll('[data-otp-slot]')]
                .map((s) => s.value)
                .join('');
        },
    }"
>
    <x-slot:header>
        <div class="px-6 pt-6 text-center">
            <h1 class="text-xl font-semibold text-slate-950" x-text="heading">Authentication code</h1>
            <p class="text-sm text-slate-600" x-text="description">
                Enter the authentication code provided by your authenticator application.
            </p>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Authenticator code --}}
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4" x-show="!recovery"
              @if ($startInRecovery) style="display: none" @endif>
            @csrf
            <input type="hidden" name="code" x-bind:value="code">

            <div class="flex flex-col items-center justify-center space-y-3 text-center">
                <div
                    data-otp-group
                    class="flex w-full items-center justify-center gap-2"
                    x-on:paste.prevent="
                        const digits = ($event.clipboardData.getData('text').match(/\d/g) || []).slice(0, 6);
                        const slots = $el.querySelectorAll('[data-otp-slot]');
                        slots.forEach((slot, i) => (slot.value = digits[i] ?? ''));
                        code = digits.join('');
                        slots[Math.min(digits.length, 5)].focus();
                    "
                >
                    @for ($i = 0; $i < 6; $i++)
                        <input
                            data-otp-slot
                            type="text"
                            inputmode="numeric"
                            autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                            maxlength="1"
                            aria-label="Digit {{ $i + 1 }}"
                            @if ($i === 0) autofocus @endif
                            class="size-11 rounded-lg border border-slate-200 text-center text-lg font-semibold shadow-xs outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-100"
                            x-on:input="
                                $el.value = $el.value.replace(/\D/g, '').slice(0, 1);
                                if ($el.value && $el.nextElementSibling) $el.nextElementSibling.focus();
                                syncFromSlots($el);
                            "
                            x-on:keydown.backspace="
                                if (!$el.value && $el.previousElementSibling) {
                                    $el.previousElementSibling.focus();
                                    $el.previousElementSibling.value = '';
                                }
                                syncFromSlots($el);
                            "
                            x-on:keydown.arrow-left="$el.previousElementSibling?.focus()"
                            x-on:keydown.arrow-right="$el.nextElementSibling?.focus()"
                        >
                    @endfor
                </div>
                <x-ui.input-error name="code" />
            </div>

            <x-ui.submit class="w-full">Continue</x-ui.submit>

            <div class="text-center text-sm text-slate-600">
                <span>or you can </span>
                <button type="button" x-on:click="toggle()" x-text="toggleText"
                        class="font-medium text-blue-600 underline underline-offset-4 transition-colors duration-300 ease-out hover:text-blue-700">
                    login using a recovery code
                </button>
            </div>
        </form>

        {{-- Recovery code --}}
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4" x-cloak x-show="recovery">
            @csrf
            <x-ui.input
                name="recovery_code"
                type="text"
                placeholder="Enter recovery code"
                x-bind:required="recovery"
            />
            <x-ui.input-error name="recovery_code" />

            <x-ui.submit class="w-full">Continue</x-ui.submit>

            <div class="text-center text-sm text-slate-600">
                <span>or you can </span>
                <button type="button" x-on:click="toggle()" x-text="toggleText"
                        class="font-medium text-blue-600 underline underline-offset-4 transition-colors duration-300 ease-out hover:text-blue-700">
                    login using an authentication code
                </button>
            </div>
        </form>
    </div>
</x-layouts.auth>
