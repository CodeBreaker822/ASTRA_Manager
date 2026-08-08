@props(['divider'])

<a href="/auth/google/redirect"
   class="flex h-11 w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 transition hover:border-blue-300 hover:bg-blue-50">
    <span class="grid size-6 place-items-center rounded-full border border-slate-200 font-bold text-blue-600"
          aria-hidden="true">G</span>
    Continue with Google
</a>

<x-ui.input-error name="google" class="mt-3" />

<div class="my-5 flex items-center gap-3 text-xs text-slate-500">
    <span class="h-px flex-1 bg-slate-200"></span>
    <span>{{ $divider }}</span>
    <span class="h-px flex-1 bg-slate-200"></span>
</div>
