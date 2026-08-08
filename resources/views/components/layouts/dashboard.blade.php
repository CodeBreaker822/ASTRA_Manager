@props(['title' => null, 'breadcrumbs' => null, 'entries' => []])

<x-layouts.app
    :title="$title"
    :entries="$entries"
    :breadcrumbs="$breadcrumbs ?? [['title' => 'Dashboard', 'href' => route('dashboard')]]"
>
    <main class="px-6 py-6">
        {{ $slot }}
    </main>
</x-layouts.app>
