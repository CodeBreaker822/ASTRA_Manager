<x-layouts.dashboard :title="$title">
    <form method="POST" action="{{ route('dashboard.pages.update', $pageKey) }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="sticky top-0 z-10 -mx-6 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-6 py-3 backdrop-blur">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-icon name="file-pen-line" class="size-5 text-blue-600" />
                    <h1 class="text-xl font-semibold text-slate-950">{{ $title }}</h1>
                </div>
                <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <select
                    aria-label="Choose page"
                    class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    x-on:change="
                        if ($el.value !== @js($pageKey)) {
                            window.location = `/dashboard/pages/${$el.value}`;
                        }
                    "
                >
                    @foreach ($pageOptions as $option)
                        <option value="{{ $option['key'] }}" @selected($option['key'] === $pageKey)>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>

                <x-ui.button :href="$previewUrl" variant="outline" target="_blank" rel="noopener">
                    <x-icon name="external-link" />
                    Preview
                </x-ui.button>

                <x-ui.submit>
                    <x-icon name="save" />
                    Save
                </x-ui.submit>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                <div class="flex items-center gap-2 text-sm font-semibold text-red-800">
                    <x-icon name="circle-alert" />
                    {{ $errors->count() }} field{{ $errors->count() === 1 ? '' : 's' }} need attention
                </div>
                <ul class="mt-2 grid gap-1 text-sm text-red-700">
                    @foreach (array_unique($errors->all()) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start">
            <aside class="lg:sticky lg:top-24">
                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <div class="mb-2 flex items-center gap-2 px-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <x-icon name="layout-template" class="size-3.5" />
                        Sections
                    </div>
                    <nav class="grid gap-0.5">
                        @foreach ($sections as $section)
                            <a href="#section-{{ $section['key'] }}"
                               class="rounded-md px-2 py-1.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700">
                                {{ $section['label'] }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                @if ($seoTitle || $seoDescription)
                    <div class="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                        <div class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <x-icon name="search" class="size-3.5" />
                            Search preview
                        </div>
                        <p class="truncate text-sm font-medium text-blue-800">{{ $seoTitle }}</p>
                        <p class="mt-1 line-clamp-3 text-xs leading-5 text-slate-600">{{ $seoDescription }}</p>
                    </div>
                @endif
            </aside>

            <div class="grid gap-5">
                @foreach ($sections as $section)
                    <section id="section-{{ $section['key'] }}" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-4">
                        <header class="mb-4 border-b border-slate-200 pb-3">
                            <h2 class="text-base font-semibold text-slate-950">{{ $section['label'] }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ $section['description'] }}</p>
                        </header>

                        <x-cms.field
                            :value="$content[$section['key']] ?? $schema[$section['key']]"
                            :schema="$schema[$section['key']]"
                            :field-key="$section['key']"
                            :path="'content.'.$section['key']"
                            :name="'content['.$section['key'].']'"
                            hide-label
                        />
                    </section>
                @endforeach
            </div>
        </div>
    </form>
</x-layouts.dashboard>
