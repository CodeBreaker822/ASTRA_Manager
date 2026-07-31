<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="application-name" content="JERVA Transcriber">
        <meta name="description" content="Use JERVA Transcriber for online audio transcription, transcript cleanup, summaries, and exports.">
        <meta name="image" content="{{ asset('JervaLogo.png') }}">

        <link rel="icon" href="/JervaLogo.png" type="image/png">
        <link rel="apple-touch-icon" href="/JervaLogo.png">

        <title>{{ config('app.name', 'JERVA Transcriber') }}</title>

        @vite(['resources/css/app.css'])
        <script src="{{ asset('js/jquery.min.js') }}"></script>
        <script src="{{ asset('js/notification.js') }}" defer></script>
        <script src="{{ asset('js/loader.js') }}" defer></script>
    </head>
    <body class="font-sans antialiased">
        {{ $slot }}
    </body>
</html>
