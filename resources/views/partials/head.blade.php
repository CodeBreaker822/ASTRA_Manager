<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="application-name" content="{{ $site['brand']['name'] ?? config('app.name') }}">

<script>
    (function () {
        var stored = localStorage.getItem('appearance') || 'system';
        var dark = stored === 'dark' || (stored === 'system'
            && window.matchMedia('(prefers-color-scheme: dark)').matches);

        if (dark) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>

<style>
    html { background-color: oklch(1 0 0); }
    html.dark { background-color: oklch(0.145 0 0); }
</style>

<link rel="icon" href="/favicon.ico" type="image/x-icon" sizes="any">
<link rel="apple-touch-icon" href="/JervaLogo.png">

@stack('seo')

<script src="{{ asset('js/jquery.min.js') }}"></script>
<script src="{{ asset('js/notification.js') }}" defer></script>
@vite(array_merge(['resources/css/app.css'], $entries ?? [], ['resources/js/blade.js']))
