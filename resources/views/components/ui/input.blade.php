@props(['name' => null, 'type' => 'text', 'id' => null])

<input
    type="{{ $type }}"
    @if ($name) name="{{ $name }}" @endif
    @if ($id ?? $name) id="{{ $id ?? $name }}" @endif
    @if (($name && $errors->has($name))) aria-invalid="true" @endif
    {{ $attributes->class([
        'flex h-10 w-full min-w-0 rounded-lg border border-slate-200 bg-background px-3 py-2 text-sm shadow-xs transition-colors outline-none',
        'placeholder:text-muted-foreground file:inline-flex file:border-0 file:bg-transparent file:text-sm file:font-medium',
        'focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-100',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'border-destructive ring-2 ring-red-100' => ($name && $errors->has($name)),
    ]) }}
>
