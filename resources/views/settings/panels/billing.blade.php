<h1 class="sr-only">Billing</h1>

<div class="space-y-8">
    <x-ui.heading variant="small" title="Billing" description="Free tier first, then credit balance" />

    <section class="grid gap-4 rounded-lg border border-green-200 bg-green-50 p-5 text-green-950">
        <div class="flex items-center gap-2">
            <x-icon name="dollar-sign" class="size-5 text-green-700" />
            <div>
                <p class="text-xs font-semibold tracking-wide text-green-600 uppercase">Credit Balance</p>
                <p class="text-xl font-bold">{{ $walletBalanceLabel }}</p>
            </div>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-950">Free Tier</h2>
                    <p class="mt-1 text-sm text-slate-600">Used before credit balance is charged</p>
                </div>
                <span class="rounded-lg border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700">
                    Daily Free
                </span>
            </div>

            <div class="mt-6 space-y-4">
                @foreach ($freeTierItems as [$title, $note])
                    <div class="flex items-start gap-3">
                        <x-icon name="check" class="mt-0.5 size-5 shrink-0 text-green-600" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">{{ $title }}</p>
                            @if ($note)
                                <p class="mt-0.5 text-xs text-slate-500">{{ $note }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        @if ($paygRates)
            <article class="rounded-lg border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-950">Pay-as-you-go</h2>
                        <p class="mt-1 text-sm text-slate-600">These rates are deducted from credit balance</p>
                    </div>
                    <span class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                        Active
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    @foreach ($paygRates as $row)
                        <div class="flex items-start justify-between rounded-lg bg-white p-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-950">{{ $row['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $row['note'] }}</p>
                            </div>
                            <p class="text-sm font-bold text-slate-950">{{ $row['rate'] }}</p>
                        </div>
                    @endforeach
                </div>

                <form
                    method="POST"
                    action="{{ route('billing.checkout') }}"
                    class="mt-8"
                    x-data="{
                        amount: '',
                        rate: {{ $topup['usd_to_php_rate'] ? (float) $topup['usd_to_php_rate'] : 'null' }},
                        get pesos() {
                            const value = Number(this.amount);

                            return value > 0 && this.rate
                                ? new Intl.NumberFormat('en-PH', {
                                    style: 'currency',
                                    currency: 'PHP',
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2,
                                }).format(value * this.rate)
                                : null;
                        },
                        get dollars() {
                            return new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: 'USD',
                                minimumFractionDigits: 2,
                            }).format(Number(this.amount) || 0);
                        },
                    }"
                    x-on:submit="
                        if (!Number(amount) || Number(amount) < 1) {
                            $event.preventDefault();
                            window.showNotification('Minimum top-up is $1.00.', 'error');
                        }
                    "
                >
                    @csrf

                    <label for="topup-amount" class="text-sm font-semibold text-slate-700">Add credit balance</label>

                    <div class="mt-3 flex gap-2">
                        <input
                            id="topup-amount"
                            name="amount"
                            x-model="amount"
                            type="number"
                            min="1"
                            step="0.01"
                            placeholder="Amount in USD"
                            value="{{ old('amount') }}"
                            class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        >
                        <x-ui.submit :disabled="! $billing['checkout_available']">
                            <x-icon name="plus" class="mr-2 size-4" />
                            Top Up
                        </x-ui.submit>
                    </div>

                    <x-ui.input-error name="amount" class="mt-2 text-xs" />
                    <x-ui.input-error name="billing" class="mt-2 text-xs" />

                    @if (! $billing['checkout_available'])
                        <p class="mt-2 text-xs text-red-600">PayMongo checkout is not configured.</p>
                    @else
                        <p class="mt-2 text-xs text-slate-500" x-cloak x-show="pesos">
                            PayMongo charges <span x-text="pesos"></span> through {{ $paymentMethods }}
                            before PayMongo fees.
                            {{ $topup['pass_on_fees']
                                ? 'PayMongo fees are added at checkout.'
                                : 'PayMongo fees may be deducted from settlement.' }}
                            Your wallet receives <span x-text="dollars"></span> after payment confirmation.
                        </p>
                        <p class="mt-2 text-xs text-slate-500" x-show="!pesos">
                            Live USD to PHP rate and PayMongo fees are checked when checkout starts.
                        </p>
                    @endif
                </form>
            </article>
        @endif
    </section>
</div>
