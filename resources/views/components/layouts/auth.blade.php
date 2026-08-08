@props(['title' => null, 'heading' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $title ?? config('app.name', 'JERVA Transcriber') }}</title>
</head>
<body class="font-sans antialiased">
<div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-slate-50 p-6 text-slate-950 md:p-10">
    <div class="flex w-full max-w-sm flex-col gap-6">
        <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 self-center font-medium">
            <div class="flex h-12 w-12 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                <x-ui.logo class="size-8" />
            </div>
            <span class="sr-only">{{ $heading ?? '' }}</span>
        </a>

        <div {{ $attributes->class('rounded-lg border border-slate-200 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.08)]') }}>
            @isset($header)
                {{ $header }}
            @else
                <div class="px-6 pt-6 text-center">
                    <h1 class="text-xl font-semibold text-slate-950">{{ $heading ?? '' }}</h1>
                    <p class="text-sm text-slate-600">{{ $description ?? '' }}</p>
                </div>
            @endisset
            <div class="px-6 py-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

@include('partials.flash')
</body>
</html>
