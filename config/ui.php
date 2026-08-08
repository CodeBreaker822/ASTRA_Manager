<?php

/*
|--------------------------------------------------------------------------
| UI class tokens
|--------------------------------------------------------------------------
|
| Tailwind class strings that more than one element reuses. Keeping them here
| rather than in a @php block means blade files stay pure markup and a token
| only has to change in one place.
|
*/

return [

    'button' => [
        'base' => "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-semibold transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-100",

        'variants' => [
            'default' => 'bg-primary text-primary-foreground hover:bg-blue-700',
            'destructive' => 'bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-red-100',
            'outline' => 'border border-slate-200 bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
            'secondary' => 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
            'ghost' => 'hover:bg-accent hover:text-accent-foreground',
            'link' => 'text-primary underline-offset-4 hover:underline',
        ],

        'sizes' => [
            'default' => 'h-10 px-4 py-2 has-[>svg]:px-3',
            'sm' => 'h-9 gap-1.5 px-3 has-[>svg]:px-2.5',
            'lg' => 'h-12 px-6 has-[>svg]:px-4',
            'icon' => 'size-10',
            'icon-sm' => 'size-9',
            'icon-lg' => 'size-12',
        ],
    ],

    'badge' => [
        'base' => 'inline-flex w-fit shrink-0 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',

        'variants' => [
            'default' => 'border-transparent bg-primary text-primary-foreground',
            'secondary' => 'border-transparent bg-secondary text-secondary-foreground',
            'destructive' => 'border-transparent bg-destructive text-white',
            'outline' => 'text-foreground',
        ],
    ],

    'dropdown' => [
        'alignments' => [
            'start' => 'left-0 origin-top-left',
            'center' => 'left-1/2 -translate-x-1/2 origin-top',
            'end' => 'right-0 origin-top-right',
        ],
    ],

    'workspace' => [
        'dock' => [
            'panel' => 'order-1 flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition sm:w-fit sm:gap-3',
            'mode_button' => 'h-12 min-w-32 flex-1 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-40 sm:flex-none',
            'action_primary' => 'inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50',
            'action_plain' => 'inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50',
        ],

        'modal' => [
            'shell' => 'hidden fixed inset-0 z-50 grid place-items-center bg-blue-950/30 p-4',
            'card' => 'w-full rounded-lg border border-slate-200 bg-white p-4 shadow-2xl',
            'cancel' => 'h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50',
        ],
    ],

    /*
    | Icons paired positionally with CMS-managed content rows. The copy is
    | editable in the CMS; the icon sequence is a design decision, so it lives
    | here rather than in the editable content.
    */
    'marketing' => [
        'landing' => [
            'use_cases' => ['briefcase-business', 'graduation-cap', 'podcast'],
        ],

        'audio_to_text' => [
            'benefits' => ['audio-lines', 'search-check', 'laptop', 'file-output'],
            'workflow' => ['upload', 'audio-lines', 'file-output'],
            'use_cases' => ['briefcase-business', 'headphones', 'graduation-cap', 'podcast'],
        ],

        'workspace_preview' => [
            'actions' => ['mic', 'upload', 'sparkles', 'file-text'],
            'action_variants' => ['default', 'outline'],
        ],
    ],

    'dashboard' => [
        'input' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500',
        'toggle_track' => "h-5 w-10 rounded-full bg-gray-200 after:absolute after:top-[2px] after:left-[2px] after:size-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-5",
    ],

];
