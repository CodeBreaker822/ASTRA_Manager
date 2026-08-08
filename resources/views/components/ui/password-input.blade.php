@props(['name' => 'password'])

<div class="relative" x-data="{ show: false }">
    <x-ui.input
        :name="$name"
        x-bind:type="show ? 'text' : 'password'"
        type="password"
        {{ $attributes->class('pr-10') }}
    />
    <button
        type="button"
        x-on:click="show = !show"
        x-bind:aria-label="show ? 'Hide password' : 'Show password'"
        aria-label="Show password"
        tabindex="-1"
        class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-slate-600 hover:text-slate-950 focus-visible:ring-2 focus-visible:ring-blue-100 focus-visible:outline-none"
    >
        <svg x-show="!show" class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1 1 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178a1 1 0 010 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <svg x-cloak x-show="show" class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 002.036 11.68a1 1 0 000 .639C3.423 16.49 7.36 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.638 0 8.573 3.007 9.963 7.178a1 1 0 010 .639 10.51 10.51 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243" />
        </svg>
    </button>
</div>
