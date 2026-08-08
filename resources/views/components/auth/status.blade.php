@props(['status' => null])

@if ($status)
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-center text-sm font-medium text-green-700">
        {{ $slot->isEmpty() ? $status : $slot }}
    </div>
@endif
