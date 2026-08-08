<x-layouts.workspace title="Workspace" :entries="['resources/js/workspace/index.js']">
    <main class="min-h-dvh bg-white text-[14px] leading-5 text-slate-950 lg:h-screen lg:min-h-0 lg:overflow-hidden"
          data-workspace
          data-project-id="{{ $project['id'] ?? '' }}"
          data-can-use-live="{{ $canUseLive ? '1' : '' }}"
          data-has-pending="{{ $hasPendingWork ? '1' : '' }}">
        <div class="flex min-h-dvh flex-col overflow-y-auto lg:h-screen lg:min-h-0 lg:flex-row lg:overflow-hidden">

            <aside class="flex max-h-[48dvh] w-full shrink-0 flex-col border-b border-slate-200 bg-slate-50 lg:max-h-none lg:min-h-0 lg:w-[19rem] lg:border-r lg:border-b-0">
                <div class="border-b border-slate-200 p-4">
                    <div class="flex h-[72px] items-center gap-3 px-2">
                        <img src="/JervaLogo.png" alt="JERVA Transcriber" class="h-10 w-10 shrink-0 object-contain">
                        <div class="min-w-0">
                            <h1 class="text-base font-semibold text-slate-950">JERVA Transcriber</h1>
                        </div>
                    </div>

                    <button type="button" data-open-modal="#create-project-modal"
                            class="mt-5 flex h-11 w-full cursor-pointer items-center justify-center rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white transition hover:bg-blue-700">
                        Add Transcript
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4">
                    <p class="text-xs font-semibold tracking-wide text-slate-600 uppercase">Recent</p>
                    <div class="mt-3 grid gap-2">
                        @forelse ($projects as $item)
                            <div @class([
                                'flex min-h-11 w-full items-center gap-1 rounded-lg py-1 pr-1.5 pl-3 text-left text-sm leading-5 transition',
                                'bg-blue-100 font-semibold text-blue-800 shadow-[inset_3px_0_0_#2563eb]' => ($project['id'] ?? null) === $item['id'],
                                'text-slate-950 hover:bg-blue-50 hover:text-blue-700' => ($project['id'] ?? null) !== $item['id'],
                            ])>
                                <a href="/workspace/{{ $item['id'] }}" class="min-w-0 flex-1 cursor-pointer py-1">
                                    <span class="block truncate font-medium">{{ $item['title'] }}</span>
                                </a>
                                <button type="button"
                                        data-delete-project="{{ $item['id'] }}"
                                        data-project-title="{{ $item['title'] }}"
                                        data-transcripts-count="{{ $item['transcripts_count'] }}"
                                        aria-label="Delete {{ $item['title'] }}"
                                        class="grid size-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-600">
                                    <x-icon name="trash-2" />
                                </button>
                            </div>
                        @empty
                            <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                                No transcripts yet.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="border-t border-slate-200 p-4">
                    <a href="{{ route('billing.edit') }}"
                       aria-label="View free minutes and credit balance"
                       class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-200 bg-white text-left transition-colors hover:border-slate-300 hover:bg-slate-50">
                        <div class="min-w-0 px-3 py-2.5">
                            <p class="text-[11px] font-medium text-slate-500">Free Minutes</p>
                            <p class="mt-0.5 truncate text-sm text-slate-950">
                                <span class="font-semibold">{{ $entitlements['usage']['minutes_remaining'] }}</span>
                            </p>
                        </div>
                        <div class="min-w-0 border-l border-slate-200 px-3 py-2.5">
                            <p class="text-[11px] font-medium text-slate-500">Credit</p>
                            <p class="mt-0.5 truncate text-sm font-semibold text-slate-950">{{ $creditBalance }}</p>
                        </div>
                    </a>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-950">{{ $user->email }}</p>
                            <p class="text-xs text-slate-600">Signed in</p>
                        </div>
                        <a href="{{ route('profile.edit') }}" aria-label="Settings"
                           class="grid size-10 place-items-center rounded-lg hover:bg-accent hover:text-accent-foreground">
                            <x-icon name="settings" class="size-5" />
                        </a>
                    </div>
                </div>
            </aside>

            <section class="relative flex min-h-[70dvh] min-w-0 flex-1 flex-col bg-white lg:min-h-0">
                <header class="flex h-[72px] shrink-0 items-center justify-between border-b border-slate-200 px-4 lg:px-6">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">Transcript</p>
                        <h2 class="truncate text-lg font-semibold text-slate-950">{{ $title }}</h2>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <a href="{{ route('profile.edit') }}" aria-label="Settings"
                           class="grid size-10 place-items-center rounded-lg border border-slate-200 hover:bg-accent">
                            <x-icon name="settings" class="size-5" />
                        </a>
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-hidden">
                    <div class="h-full w-full [scrollbar-gutter:stable] overflow-y-auto px-4 pt-6 pb-40 lg:px-8 lg:pb-32">
                        <div id="upgrade-banner" hidden
                             class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                            <span id="upgrade-message"></span>
                            <a href="{{ route('billing.edit') }}" class="shrink-0 font-semibold text-blue-700">View plans</a>
                        </div>

                        <div id="transcript-body">
                            @include('workspace.partials.transcript')
                        </div>
                    </div>
                </div>

                @if ($project)
                    @include('workspace.partials.dock')
                @endif
            </section>
        </div>
    </main>

    <div id="create-project-modal" data-modal class="hidden fixed inset-0 z-50 grid place-items-center bg-blue-950/30 p-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-4 shadow-2xl">
            <h3 class="text-base font-semibold text-slate-950">Add Transcript</h3>
            <form method="POST" action="{{ route('workspace.store') }}" class="mt-4 grid gap-4">
                @csrf
                <div class="grid gap-2">
                    <x-ui.label for="project-title">Transcript name</x-ui.label>
                    <x-ui.input id="project-title" name="title" autofocus placeholder="Project or conversation name"
                                value="{{ old('title') }}" />
                    <x-ui.input-error name="title" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal="#create-project-modal"
                            class="h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <x-ui.submit class="h-10 px-4">Add</x-ui.submit>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-project-modal" data-modal class="hidden fixed inset-0 z-50 grid place-items-center bg-blue-950/30 p-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-4 shadow-2xl">
            <h3 id="delete-project-title" class="text-base font-semibold text-slate-950"></h3>
            <p id="delete-project-body" class="mt-2 text-sm text-slate-600"></p>
            <form id="delete-project-form" method="POST" class="mt-4 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" data-close-modal="#delete-project-modal"
                        class="h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <x-ui.submit variant="destructive" class="h-10 px-4">Delete</x-ui.submit>
            </form>
        </div>
    </div>

    @if ($errors->has('title'))
        @push('scripts')
            <script>document.getElementById('create-project-modal').classList.remove('hidden');</script>
        @endpush
    @endif
</x-layouts.workspace>
