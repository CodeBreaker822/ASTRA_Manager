<x-layouts.dashboard title="Dashboard">
    <div class="mx-auto max-w-[1600px] space-y-5">
        @if ($overview)
            <section class="space-y-5 text-slate-900">
                <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Business overview</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Realized credit revenue, wallet liability, traffic, and registrations.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-slate-400">Trend:</span>
                        <div class="inline-flex rounded-md border border-slate-200 bg-white p-0.5">
                            @foreach ($dayOptions as $days)
                                <a href="/dashboard?days={{ $days }}"
                                   @class([
                                       'rounded px-2.5 py-1.5 text-xs font-medium',
                                       'bg-slate-900 text-white' => $overview['days'] === $days,
                                       'text-slate-500 hover:text-slate-900' => $overview['days'] !== $days,
                                   ])>{{ $days }}d</a>
                            @endforeach
                        </div>
                        <a href="{{ route('dashboard.analytics.users.export') }}"
                           class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700">
                            <x-icon name="download" />
                            Export user audit
                        </a>
                    </div>
                </header>

                <div class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-200 bg-white lg:grid-cols-4">
                    <div class="border-r border-b border-slate-200 p-4 lg:border-b-0">
                        <p class="text-xs font-medium text-slate-500">Realized credits · lifetime</p>
                        <p class="mt-1 text-xl font-semibold text-emerald-600">
                            {{ $overview['realized_credits'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">{{ $overview['realization_rate'] }}% of paid funding consumed</p>
                    </div>
                    <div class="border-b border-slate-200 p-4 lg:border-r lg:border-b-0">
                        <p class="text-xs font-medium text-slate-500">Refundable balances · current</p>
                        <p class="mt-1 text-xl font-semibold text-amber-600">
                            {{ $overview['refundable_balance'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">Current wallet liability</p>
                    </div>
                    <div class="border-r border-slate-200 p-4">
                        <p class="text-xs font-medium text-slate-500">Paid top-ups · lifetime</p>
                        <p class="mt-1 text-xl font-semibold">{{ $overview['paid_topups'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $overview['paid_transactions'] }} confirmed payments
                        </p>
                    </div>
                    <div class="p-4">
                        <p class="text-xs font-medium text-slate-500">Registered users · total</p>
                        <p class="mt-1 text-xl font-semibold">{{ $overview['registered_users'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $overview['active_users'] }} active · {{ $overview['verified_users'] }} verified
                        </p>
                    </div>
                </div>

                @if ($overview['reconciliation']['needed'])
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <span class="font-semibold">Reconciliation review:</span>
                        {{ $overview['reconciliation']['historical'] }} was consumed before exact
                        charge tracking, and {{ $overview['reconciliation']['excess'] }} in balances or
                        adjustments is not reconciled to tracked paid funding. The user audit export identifies each
                        affected account.
                    </div>
                @endif

                <div class="grid gap-5 xl:grid-cols-3">
                    <section class="rounded-lg border border-slate-200 bg-white p-4 xl:col-span-2">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold">Sales and consumption</h2>
                                <p class="mt-1 text-xs text-slate-500">
                                    Last {{ $overview['trend']['days'] }} days shown; exact consumed charges begin with this
                                    dashboard update.
                                </p>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="size-2 rounded-sm bg-blue-500"></span> Paid
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="size-2 rounded-sm bg-emerald-500"></span> Consumed
                                </span>
                            </div>
                        </div>

                        <div class="mt-5 overflow-x-auto pb-1">
                            <div class="flex h-40 min-w-[560px] items-end gap-2 border-b border-slate-200">
                                @foreach ($overview['trend']['points'] as $point)
                                    <div class="group flex h-full min-w-7 flex-1 flex-col justify-end" title="{{ $point['title'] }}">
                                        <div class="flex flex-1 items-end justify-center gap-0.5">
                                            <div class="w-2.5 rounded-t bg-blue-500 transition-all"
                                                 style="height: {{ $point['paid_height'] }}"></div>
                                            <div class="w-2.5 rounded-t bg-emerald-500 transition-all"
                                                 style="height: {{ $point['consumed_height'] }}"></div>
                                        </div>
                                        <span class="mt-2 truncate text-center text-[10px] text-slate-400">
                                            {{ $point['label'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 text-xs sm:grid-cols-3">
                            <div class="rounded-md bg-slate-50 p-3">
                                <span class="text-slate-500">Exact tracked charges</span>
                                <p class="mt-1 font-semibold">{{ $overview['tracked_charges'] }}</p>
                            </div>
                            <div class="rounded-md bg-slate-50 p-3">
                                <span class="text-slate-500">Pending checkouts</span>
                                <p class="mt-1 font-semibold">{{ $overview['pending_topups'] }}</p>
                            </div>
                            <div class="rounded-md bg-slate-50 p-3">
                                <span class="text-slate-500">Realized basis</span>
                                <p class="mt-1 font-semibold">Paid top-ups − current balance</p>
                            </div>
                        </div>

                        <p class="mt-3 text-[11px] leading-5 text-slate-400">
                            &ldquo;Realized credits&rdquo; is gross consumed wallet revenue, not net profit; provider
                            and operating costs are not stored by billing.
                        </p>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <h2 class="text-sm font-semibold">Popular pages</h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Human visits in the last {{ $overview['days'] }} days
                            </p>
                        </div>
                        <div class="max-h-[330px] overflow-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="sticky top-0 bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">Page</th>
                                        <th class="px-3 py-2 text-right font-medium">Visits</th>
                                        <th class="px-4 py-2 text-right font-medium">Users</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($overview['popular_pages'] as $item)
                                        <tr>
                                            <td class="max-w-52 px-4 py-2.5">
                                                <p class="truncate font-medium" title="{{ $item['path'] }}">{{ $item['path'] }}</p>
                                                <p class="mt-0.5 truncate text-[10px] text-slate-400">{{ $item['route'] }}</p>
                                            </td>
                                            <td class="px-3 py-2.5 text-right font-semibold">{{ $item['human_visits'] }}</td>
                                            <td class="px-4 py-2.5 text-right text-slate-500">{{ $item['authenticated_visits'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-4 py-8 text-center text-slate-400">
                                                Visit data will appear as pages are opened.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <div>
                            <h2 class="text-sm font-semibold">Recent paid sales</h2>
                            <p class="mt-1 text-xs text-slate-500">Latest confirmed wallet funding</p>
                        </div>
                        <span class="text-xs text-slate-400">USD</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 font-medium">User</th>
                                    <th class="px-4 py-2 font-medium">Reference</th>
                                    <th class="px-4 py-2 font-medium">Paid</th>
                                    <th class="px-4 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($overview['recent_sales'] as $sale)
                                    <tr>
                                        <td class="px-4 py-2.5 font-medium whitespace-nowrap">{{ $sale['email'] }}</td>
                                        <td class="px-4 py-2.5 font-mono text-[11px] whitespace-nowrap text-slate-500">{{ $sale['reference'] }}</td>
                                        <td class="px-4 py-2.5 whitespace-nowrap text-slate-500">{{ $sale['paid_at'] }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold whitespace-nowrap text-emerald-600">
                                            {{ $sale['amount'] }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-7 text-center text-slate-400">No paid top-ups yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>
        @endif

        @if ($shortcuts)
            <section>
                @if ($overview)
                    <h2 class="mb-2 text-xs font-semibold tracking-wide text-slate-400 uppercase">Management</h2>
                @endif
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($shortcuts as $shortcut)
                        <a href="{{ $shortcut['href'] }}"
                           class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 transition hover:border-blue-300 hover:bg-blue-50">
                            <x-icon :name="$shortcut['icon']" class="size-4 text-blue-600" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-950">{{ $shortcut['title'] }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $shortcut['body'] }}</p>
                            </div>
                            <x-icon name="arrow-up-right" class="size-4 text-slate-400" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.dashboard>
