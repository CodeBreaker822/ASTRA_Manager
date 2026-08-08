@props(['pricing'])

{{-- The facts are assembled by App\Support\PricingProof. --}}
<div
    aria-label="{{ $site['pricing_proof']['aria_label'] }}"
    {{ $attributes->class('grid overflow-hidden rounded-lg border border-blue-100 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.06)] sm:grid-cols-2 lg:grid-cols-5') }}
>
    @foreach (\App\Support\PricingProof::facts($pricing, $site['pricing_proof']) as $fact)
        <div class="border-b border-blue-100 px-4 py-5 text-center last:border-b-0 sm:border-r lg:border-b-0 lg:last:border-r-0 sm:[&:nth-child(even)]:border-r-0 lg:[&:nth-child(even)]:border-r">
            <p class="text-xl font-semibold text-blue-700">{{ $fact['value'] }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-600">{{ $fact['label'] }}</p>
        </div>
    @endforeach
</div>
