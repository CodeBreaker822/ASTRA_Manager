@props(['user'])

<span class="relative flex size-8 shrink-0 overflow-hidden rounded-lg">
    @if (! empty($user->avatar))
        <img src="{{ $user->avatar }}" alt="{{ $user->email }}" class="aspect-square size-full object-cover">
    @else
        <span class="flex size-full items-center justify-center rounded-lg bg-neutral-200 text-xs font-medium text-black dark:bg-neutral-700 dark:text-white">
            {{ $user?->initials() }}
        </span>
    @endif
</span>

<div class="grid flex-1 text-left text-sm leading-tight">
    <span class="truncate font-medium">{{ $user?->email }}</span>
</div>
