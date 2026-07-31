<script setup lang="ts">
import { computed } from 'vue';
import type { MarketingPricing } from '@/types/marketing';

const props = defineProps<{
    pricing: MarketingPricing;
}>();

const money = (value: number | null): string | null => {
    if (!value || value <= 0) {
        return null;
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.pricing.currency,
        minimumFractionDigits: value < 1 ? 2 : 0,
        maximumFractionDigits: value < 1 ? 4 : 2,
    }).format(value);
};

const facts = computed(() => [
    {
        value: props.pricing.free_minutes_per_day
            ? `${props.pricing.free_minutes_per_day} min`
            : 'Free',
        label: props.pricing.free_minutes_per_day
            ? 'online every day'
            : 'daily online allowance',
    },
    {
        value: money(props.pricing.upload_price_per_hour) ?? 'Pay as you go',
        label: props.pricing.upload_price_per_hour
            ? 'per uploaded audio hour'
            : 'for extra online use',
    },
    {
        value: money(props.pricing.live_price_per_hour) ?? 'No subscription',
        label: props.pricing.live_price_per_hour
            ? 'per live audio hour'
            : 'required',
    },
    {
        value: '99+',
        label: 'Whisper languages',
    },
    {
        value: '$0',
        label: 'Windows desktop app',
    },
]);
</script>

<template>
    <div
        class="grid overflow-hidden rounded-lg border border-blue-100 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.06)] sm:grid-cols-2 lg:grid-cols-5"
        aria-label="JERVA pricing and product facts"
    >
        <div
            v-for="fact in facts"
            :key="fact.label"
            class="border-b border-blue-100 px-4 py-5 text-center last:border-b-0 sm:border-r lg:border-b-0 lg:last:border-r-0 sm:[&:nth-child(even)]:border-r-0 lg:[&:nth-child(even)]:border-r"
        >
            <p class="text-xl font-semibold text-blue-700">{{ fact.value }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-600">
                {{ fact.label }}
            </p>
        </div>
    </div>
</template>
