<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @vite(['resources/css/app.css'])
        <script src="{{ asset('js/jquery.min.js') }}"></script>
        <script src="{{ asset('js/notification.js') }}" defer></script>
        <script src="{{ asset('js/loader.js') }}" defer></script>
    </head>
    <body class="font-sans antialiased">
        {{ $slot }}
    </body>
</html>
