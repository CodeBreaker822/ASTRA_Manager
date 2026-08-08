@props(['title' => null, 'entries' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $title ?? config('app.name', 'JERVA Transcriber') }}</title>
</head>
<body class="font-sans antialiased">
{{ $slot }}

@include('partials.flash')
@stack('scripts')
</body>
</html>
