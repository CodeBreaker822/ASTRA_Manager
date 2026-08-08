{{-- `entries` adds page-specific Vite bundles; they load before blade.js so
     any Alpine.data() they register exists when Alpine.start() runs. --}}
@props(['title' => null, 'breadcrumbs' => [], 'entries' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $title ?? config('app.name', 'JERVA Transcriber') }}</title>
</head>
<body class="font-sans antialiased">
{{-- Sidebar collapse state is persisted in a cookie so it survives navigation. --}}
<div
    class="flex min-h-svh w-full bg-sidebar"
    x-data="{
        collapsed: {{ request()->cookie('sidebar_state') === 'false' ? 'true' : 'false' }},
        toggle() {
            this.collapsed = !this.collapsed;
            document.cookie = `sidebar_state=${!this.collapsed};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
        },
    }"
>
    <x-app.sidebar />

    <div class="flex min-h-svh min-w-0 flex-1 flex-col md:m-2 md:ml-0 md:rounded-xl md:border md:border-sidebar-border md:bg-background md:shadow-sm">
        <header class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 md:px-4">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="toggle()" aria-label="Toggle sidebar"
                        class="-ml-1 grid size-8 place-items-center rounded-md hover:bg-accent hover:text-accent-foreground">
                    <x-icon name="panel-left-close" class="size-4" x-show="!collapsed" x-cloak />
                    <x-icon name="panel-left-open" class="size-4" x-cloak x-show="collapsed" />
                </button>

                @if ($breadcrumbs)
                    <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm">
                        @foreach ($breadcrumbs as $i => $crumb)
                            @if ($i > 0)
                                <x-icon name="chevron-right" class="size-3.5 text-muted-foreground" />
                            @endif
                            @if (! empty($crumb['href']) && $i < count($breadcrumbs) - 1)
                                <a href="{{ $crumb['href'] }}" class="text-muted-foreground hover:text-foreground">
                                    {{ $crumb['title'] }}
                                </a>
                            @else
                                <span class="font-medium" aria-current="page">{{ $crumb['title'] }}</span>
                            @endif
                        @endforeach
                    </nav>
                @endif
            </div>
        </header>

        <main class="flex min-h-0 flex-1 flex-col overflow-x-hidden">
            {{ $slot }}
        </main>
    </div>
</div>

@include('partials.flash')
@stack('scripts')
</body>
</html>
