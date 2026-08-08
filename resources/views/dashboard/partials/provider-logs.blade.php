@if ($logs->isEmpty())
    <p class="py-8 text-center text-sm text-gray-500">
        No provider activity has been recorded for this group.
    </p>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200">
        <div class="max-h-[58vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                <thead class="sticky top-0 bg-gray-50 text-xs tracking-wide text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-3 font-medium">#</th>
                        <th class="px-3 py-3 font-medium whitespace-nowrap">Date and time</th>
                        <th class="px-3 py-3 font-medium">Activity</th>
                        <th class="px-3 py-3 font-medium">Provider / model</th>
                        <th class="px-3 py-3 font-medium">Result</th>
                        <th class="px-3 py-3 font-medium whitespace-nowrap">Attempt</th>
                        <th class="px-3 py-3 font-medium">HTTP</th>
                        <th class="px-3 py-3 font-medium">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($logs as $index => $log)
                        <tr class="align-top">
                            <td class="px-3 py-3 whitespace-nowrap text-gray-500">
                                {{ ($pagination['from'] ?: 1) + $index }}
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap text-gray-600">
                                {{ $log['logged_at'] }}
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $log['source'] }}</td>
                            <td class="px-3 py-3">
                                <p class="font-medium text-gray-900">{{ $log['provider'] ?:'Unknown provider' }}</p>
                                <p class="mt-0.5 max-w-64 text-xs break-all text-gray-500">{{ $log['model'] ?:'Unknown model' }}</p>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-800' => $log['succeeded'],
                                    'bg-red-100 text-red-800' => ! $log['succeeded'],
                                ])>{{ $log['status_label'] }}</span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap text-gray-600">{{ $log['fallback_position'] ?:'?' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-gray-600">{{ $log['http_status'] ?:'—' }}</td>
                            <td class="max-w-72 px-3 py-3 text-gray-600">
                                @if ($log['error'])
                                    <span class="text-red-700">{{ $log['error'] }}</span>
                                @else
                                    <span>—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($pagination['total'] > 0)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">
                Showing {{ $pagination['from'] }}–{{ $pagination['to'] }} of {{ $pagination['total'] }}
            </p>
            <nav class="flex items-center gap-1" aria-label="Provider log pages">
                <button type="button" data-log-page="{{ $pagination['current_page'] - 1 }}"
                        @disabled($pagination['current_page'] <= 1)
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                @foreach ($pageNumbers as $page)
                    <button type="button" data-log-page="{{ $page }}"
                            @class([
                                'min-w-9 rounded-md border px-3 py-1.5 text-sm',
                                'border-blue-600 bg-blue-600 text-white' => $page === $pagination['current_page'],
                                'border-gray-300 text-gray-700' => $page !== $pagination['current_page'],
                            ])
                            @if ($page === $pagination['current_page']) aria-current="page" @endif>{{ $page }}</button>
                @endforeach

                <button type="button" data-log-page="{{ $pagination['current_page'] + 1 }}"
                        @disabled($pagination['current_page'] >= $pagination['last_page'])
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Next
                </button>
            </nav>
        </div>
    @endif
@endif
