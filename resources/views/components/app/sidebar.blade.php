{{-- $navGroups, $navHome, and $authUser are composed on by AppServiceProvider. --}}
<aside
    class="flex shrink-0 flex-col gap-2 bg-sidebar p-2 text-sidebar-foreground transition-[width] duration-200 ease-linear"
    x-bind:class="collapsed ? 'w-[3.5rem]' : 'w-64'"
    aria-label="Sidebar"
>
    {{-- Brand --}}
    <a href="{{ $navHome }}"
       class="flex h-12 items-center gap-2 rounded-lg px-2 hover:bg-sidebar-accent">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary/10">
            <x-ui.logo class="size-6" />
        </span>
        <span class="truncate text-sm font-semibold" x-show="!collapsed" x-cloak>
            {{ config('app.name', 'JERVA Transcriber') }}
        </span>
    </a>

    {{-- Navigation --}}
    <nav class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto">
        @foreach ($navGroups as $label => $items)
            <div class="grid gap-1">
                <p class="px-2 text-xs font-medium text-sidebar-foreground/70" x-show="!collapsed" x-cloak>
                    {{ $label }}
                </p>
                @foreach ($items as $item)
                    <a
                        href="{{ $item['href'] }}"
                        title="{{ $item['title'] }}"
                        @class([
                            'flex h-9 items-center gap-2 rounded-lg px-2 text-sm font-medium transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                            'bg-sidebar-accent text-sidebar-accent-foreground' => $item['active'],
                        ])
                        @if ($item['active']) aria-current="page" @endif
                    >
                        <x-icon :name="$item['icon']" class="size-4 shrink-0" />
                        <span class="truncate" x-show="!collapsed" x-cloak>{{ $item['title'] }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    {{-- User menu --}}
    <x-ui.dropdown align="start" width="w-64">
        <x-slot:trigger>
            <button type="button" data-test="sidebar-menu-button"
                    class="flex h-12 w-full items-center gap-2 rounded-lg px-2 text-left hover:bg-sidebar-accent">
                <x-app.user-info :user="$authUser" />
                <x-icon name="chevrons-up-down" class="ml-auto size-4" x-show="!collapsed" x-cloak />
            </button>
        </x-slot:trigger>

        <div class="flex items-center gap-2 px-2 py-1.5 text-left text-sm">
            <x-app.user-info :user="$authUser" />
        </div>
        <div class="-mx-1 my-1 h-px bg-slate-200"></div>

        <a href="{{ route('profile.edit') }}" role="menuitem"
           class="flex w-full cursor-pointer items-center rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground">
            <x-icon name="settings" class="mr-2 size-4" />
            Settings
        </a>

        <div class="-mx-1 my-1 h-px bg-slate-200"></div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem" data-test="logout-button"
                    class="flex w-full cursor-pointer items-center rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground">
                <x-icon name="log-out" class="mr-2 size-4" />
                Log out
            </button>
        </form>
    </x-ui.dropdown>
</aside>
