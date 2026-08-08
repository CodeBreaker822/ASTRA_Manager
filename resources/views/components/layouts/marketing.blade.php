@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $title ?? config('app.name', 'JERVA Transcriber') }}</title>
</head>
<body class="font-sans antialiased">
<div class="min-h-screen bg-white text-slate-950">
    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
             aria-label="{{ $site['navigation']['aria_label'] }}"
             x-data="{ open: false }">
            <a href="{{ $site['brand']['home_url'] }}" class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100">
                    <x-ui.logo class="size-6" />
                </span>
                <span class="text-base font-semibold text-slate-950">{{ $site['brand']['name'] }}</span>
            </a>

            <div class="hidden items-center gap-6 md:flex">
                @foreach ($site['navigation']['items'] as $item)
                    <a href="{{ $item['url'] }}"
                       class="text-sm font-medium text-slate-700 transition-colors hover:text-blue-600">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="hidden items-center gap-3 md:flex">
                <x-ui.button :href="$site['navigation']['sign_in_url']" variant="ghost">
                    {{ $site['navigation']['sign_in_label'] }}
                </x-ui.button>
                <x-ui.button :href="$site['navigation']['primary_button_url']">
                    {{ $site['navigation']['primary_button_label'] }}
                </x-ui.button>
            </div>

            <x-ui.button
                variant="ghost"
                size="icon"
                class="md:hidden"
                x-on:click="open = true"
                aria-label="{{ $site['navigation']['mobile_open_label'] }}"
            >
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </x-ui.button>

            <div x-cloak x-show="open" class="fixed inset-0 z-50 md:hidden">
                <div class="fixed inset-0 bg-black/50" x-on:click="open = false"></div>
                <div class="fixed inset-y-0 right-0 w-3/4 max-w-sm bg-white p-6 shadow-lg"
                     x-trap.noscroll="open"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="translate-x-full"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-end="translate-x-full">
                    <div class="flex items-center justify-between">
                        <span class="text-base font-semibold text-slate-950">{{ $site['brand']['name'] }}</span>
                        <button type="button" x-on:click="open = false" aria-label="Close navigation"
                                class="text-slate-500 hover:text-slate-900">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mt-8 grid gap-3">
                        @foreach ($site['navigation']['items'] as $item)
                            <a href="{{ $item['url'] }}"
                               class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-blue-50 hover:text-blue-700">
                                {{ $item['label'] }}
                            </a>
                        @endforeach

                        <div class="mt-4 grid gap-3">
                            <x-ui.button :href="$site['navigation']['sign_in_url']" variant="outline">
                                {{ $site['navigation']['sign_in_label'] }}
                            </x-ui.button>
                            <x-ui.button :href="$site['navigation']['primary_button_url']">
                                {{ $site['navigation']['primary_button_label'] }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    {{ $slot }}

    <footer class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-8 px-6 py-12 md:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100">
                        <x-ui.logo class="size-6" />
                    </span>
                    <span class="text-base font-semibold text-slate-950">{{ $site['brand']['name'] }}</span>
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ $site['footer']['brand_body'] }}</p>
            </div>

            @foreach ($site['footer']['columns'] as $column)
                <div>
                    <h2 class="text-sm font-semibold text-slate-950">{{ $column['title'] }}</h2>
                    <div class="mt-4 grid gap-3 text-sm text-slate-600">
                        @foreach ($column['links'] as $link)
                            <a href="{{ $link['url'] }}" class="hover:text-blue-600">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div>
                <h2 class="text-sm font-semibold text-slate-950">{{ $site['footer']['workspace_title'] }}</h2>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ $site['footer']['workspace_body'] }}</p>
            </div>
        </div>
        <div class="border-t border-slate-200 px-6 py-4">
            <p class="mx-auto max-w-6xl text-sm text-slate-600">
                {{ str_replace('{year}', now()->year, $site['footer']['copyright']) }}
            </p>
        </div>
    </footer>
</div>

@include('partials.flash')
</body>
</html>
