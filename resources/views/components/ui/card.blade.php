@props(['title' => null, 'description' => null])

<div {{ $attributes->class('rounded-xl border border-slate-200 bg-card text-card-foreground shadow-sm') }}>
    @if ($title || $description)
        <div class="flex flex-col gap-1.5 px-6 pt-6">
            @if ($title)
                <h2 class="text-base leading-none font-semibold">{{ $title }}</h2>
            @endif
            @if ($description)
                <p class="text-sm text-muted-foreground">{{ $description }}</p>
            @endif
        </div>
    @endif

    <div class="p-6">{{ $slot }}</div>
</div>
