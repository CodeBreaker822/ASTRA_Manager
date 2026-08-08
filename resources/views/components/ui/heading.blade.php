@props(['title', 'description' => null, 'variant' => 'default'])

<header @class(['mb-8 space-y-0.5' => $variant !== 'small'])>
    <h2 @class([
        'mb-0.5 text-base font-medium' => $variant === 'small',
        'text-xl font-semibold tracking-tight' => $variant !== 'small',
    ])>{{ $title }}</h2>

    @if ($description)
        <p class="text-sm text-muted-foreground">{{ $description }}</p>
    @endif
</header>
