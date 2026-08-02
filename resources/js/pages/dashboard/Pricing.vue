<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Save, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type TierKey = 'free' | 'payg';

type Tier = {
    key: TierKey;
    name: string;
    tagline: string;
    monthly_price: number | null;
    yearly_price: number | null;
    price_label: string;
    upload_price_per_hour: number;
    live_price_per_hour: number;
    llm_price: number;
    polish_price_per_character: number;
    summary_price_per_character: number;
    minutes: number;
    free_polish_uses_per_day: number;
    free_summary_uses_per_day: number;
    cta: string;
    featured: boolean;
    features: string[];
    entitlements: {
        upload: boolean;
        live: boolean;
        polish: boolean;
        summarize: boolean;
        exports: string[];
    };
};

type ComparisonRow = {
    label: string;
    tier_keys: TierKey[];
};

const props = defineProps<{
    tiers: Tier[];
    comparisonRows: ComparisonRow[];
}>();

defineOptions({
    layout: DashboardLayout,
});

const form = useForm({
    tiers: props.tiers.map((tier) => ({
        ...tier,
        monthly_price: tier.monthly_price,
        yearly_price: tier.yearly_price,
        features: [...tier.features, '', '', '', ''].slice(0, 6),
        entitlements: {
            upload: tier.entitlements.upload,
            live: tier.entitlements.live,
            polish: tier.entitlements.polish,
            summarize: tier.entitlements.summarize,
            exports: [...tier.entitlements.exports],
        },
    })),
    comparisonRows: props.comparisonRows.map((row) => ({
        label: row.label,
        tier_keys: [...row.tier_keys],
    })),
});

const planKeys: TierKey[] = ['free', 'payg'];

const toggleRowTier = (row: ComparisonRow, key: TierKey) => {
    row.tier_keys = row.tier_keys.includes(key)
        ? row.tier_keys.filter((item) => item !== key)
        : [...row.tier_keys, key];
};

const addComparisonRow = () => {
    form.comparisonRows.push({
        label: '',
        tier_keys: [],
    });
};

const removeComparisonRow = (index: number) => {
    form.comparisonRows.splice(index, 1);
};

const toggleExport = (tier: Tier, format: string) => {
    tier.entitlements.exports = tier.entitlements.exports.includes(format)
        ? tier.entitlements.exports.filter((item) => item !== format)
        : [...tier.entitlements.exports, format];
};

const submit = () => {
    form.put('/dashboard/pricing', { preserveScroll: true });
};
</script>

<template>
    <Head title="Pricing Manager" />

    <form class="space-y-5" @submit.prevent="submit">
        <div
            class="sticky top-0 z-10 -mx-6 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-6 py-3 backdrop-blur"
        >
            <div>
                <h1 class="text-xl font-semibold text-slate-950">Pricing</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Free sets daily allowances. Pay as you go sets the dollar
                    rates deducted from user credit balance.
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                <Save v-else class="size-4" />
                Save
            </Button>
        </div>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold text-slate-950">Credit Packs</h2>
            <div class="overflow-x-auto pb-2">
                <div class="flex min-w-max gap-4">
                    <div
                        v-for="(tier, tierIndex) in form.tiers"
                        :key="tier.key"
                        class="w-[460px] shrink-0 rounded-md border border-slate-200 bg-white p-4"
                    >
                        <div
                            class="mb-4 flex items-start justify-between gap-3 border-b border-slate-200 pb-3"
                        >
                            <div>
                                <p
                                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                                >
                                    {{ tier.key }}
                                </p>
                                <h3
                                    class="mt-1 text-base font-semibold text-slate-950"
                                >
                                    {{ tier.name || 'Credit pack' }}
                                </h3>
                            </div>
                            <label
                                class="flex h-9 items-center gap-2 text-sm text-slate-700"
                            >
                                <input
                                    v-model="tier.featured"
                                    type="checkbox"
                                    class="size-4"
                                />
                                Featured
                            </label>
                        </div>

                        <div class="grid gap-2">
                            <label
                                :for="`name-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Pack name</span
                                >
                                <Input
                                    :id="`name-${tier.key}`"
                                    v-model="tier.name"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`minutes-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Audio minutes included</span
                                >
                                <Input
                                    :id="`minutes-${tier.key}`"
                                    v-model.number="tier.minutes"
                                    type="number"
                                    min="0"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`upload-rate-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Uploaded audio price per hour ($)</span
                                >
                                <Input
                                    :id="`upload-rate-${tier.key}`"
                                    v-model.number="tier.upload_price_per_hour"
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0.12"
                                    class="h-9 font-mono text-sm"
                                />
                                <div class="col-span-2 text-xs text-slate-400">
                                    Example: <strong>0.12</strong> = $0.12/hour
                                </div>
                            </label>

                            <label
                                :for="`live-rate-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Live recording price per hour ($)</span
                                >
                                <Input
                                    :id="`live-rate-${tier.key}`"
                                    v-model.number="tier.live_price_per_hour"
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0.15"
                                    class="h-9 font-mono text-sm"
                                />
                                <div class="col-span-2 text-xs text-slate-400">
                                    Example: <strong>0.15</strong> = $0.15/hour
                                </div>
                            </label>

                            <label
                                :for="`free-polish-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Free polishes each day</span
                                >
                                <Input
                                    :id="`free-polish-${tier.key}`"
                                    v-model.number="
                                        tier.free_polish_uses_per_day
                                    "
                                    type="number"
                                    min="0"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`free-summary-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Free summaries each day</span
                                >
                                <Input
                                    :id="`free-summary-${tier.key}`"
                                    v-model.number="
                                        tier.free_summary_uses_per_day
                                    "
                                    type="number"
                                    min="0"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`polish-rate-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Polish price per character ($)</span
                                >
                                <Input
                                    :id="`polish-rate-${tier.key}`"
                                    v-model.number="
                                        tier.polish_price_per_character
                                    "
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0.000005"
                                    class="h-9 font-mono text-sm"
                                />
                                <div class="col-span-2 text-xs text-slate-400">
                                    Stored per character. Example:
                                    <strong>0.000005</strong> displays as $0.005
                                    per 1,000 characters.
                                </div>
                            </label>

                            <label
                                :for="`summary-rate-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Summary price per character ($)</span
                                >
                                <Input
                                    :id="`summary-rate-${tier.key}`"
                                    v-model.number="
                                        tier.summary_price_per_character
                                    "
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0.000005"
                                    class="h-9 font-mono text-sm"
                                />
                                <div class="col-span-2 text-xs text-slate-400">
                                    Stored per character. Example:
                                    <strong>0.000005</strong> displays as $0.005
                                    per 1,000 characters.
                                </div>
                            </label>

                            <label
                                :for="`label-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Price shown to customers</span
                                >
                                <Input
                                    :id="`label-${tier.key}`"
                                    v-model="tier.price_label"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`tagline-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Short description</span
                                >
                                <Input
                                    :id="`tagline-${tier.key}`"
                                    v-model="tier.tagline"
                                    class="h-9"
                                />
                            </label>

                            <label
                                :for="`cta-${tier.key}`"
                                class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm"
                            >
                                <span class="font-medium text-slate-600"
                                    >Buy button text</span
                                >
                                <Input
                                    :id="`cta-${tier.key}`"
                                    v-model="tier.cta"
                                    class="h-9"
                                />
                            </label>

                            <div
                                class="grid grid-cols-[9.5rem_1fr] gap-3 text-sm"
                            >
                                <span class="pt-2 font-medium text-slate-600"
                                    >What customers get</span
                                >
                                <div class="grid gap-2">
                                    <Input
                                        v-for="(
                                            _, featureIndex
                                        ) in tier.features"
                                        :key="`${tier.key}-feature-${featureIndex}`"
                                        v-model="tier.features[featureIndex]"
                                        class="h-9"
                                    />
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-[9.5rem_1fr] gap-3 text-sm"
                            >
                                <span class="pt-2 font-medium text-slate-600"
                                    >Allowed features</span
                                >
                                <div class="grid gap-2">
                                    <label
                                        class="flex items-center gap-2 text-slate-700"
                                    >
                                        <input
                                            v-model="tier.entitlements.upload"
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        Upload audio transcription
                                    </label>
                                    <label
                                        class="flex items-center gap-2 text-slate-700"
                                    >
                                        <input
                                            v-model="tier.entitlements.live"
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        Live browser transcription
                                    </label>
                                    <label
                                        class="flex items-center gap-2 text-slate-700"
                                    >
                                        <input
                                            v-model="tier.entitlements.polish"
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        Transcript polishing
                                    </label>
                                    <label
                                        class="flex items-center gap-2 text-slate-700"
                                    >
                                        <input
                                            v-model="
                                                tier.entitlements.summarize
                                            "
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        Transcript summarization
                                    </label>
                                    <div class="flex flex-wrap gap-3 pt-1">
                                        <label
                                            v-for="format in [
                                                'txt',
                                                'docx',
                                                'xlsx',
                                            ]"
                                            :key="`${tier.key}-${format}`"
                                            class="flex items-center gap-2 text-slate-700 uppercase"
                                        >
                                            <input
                                                type="checkbox"
                                                class="size-4"
                                                :checked="
                                                    tier.entitlements.exports.includes(
                                                        format,
                                                    )
                                                "
                                                @change="
                                                    toggleExport(tier, format)
                                                "
                                            />
                                            {{ format }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <Input
                                :id="`llm-rate-${tier.key}`"
                                v-model.number="tier.llm_price"
                                type="hidden"
                            />

                            <div
                                v-if="form.errors[`tiers.${tierIndex}.name`]"
                                class="text-sm text-red-600"
                            >
                                {{ form.errors[`tiers.${tierIndex}.name`] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-950">Comparison</h2>
                <Button
                    type="button"
                    variant="outline"
                    @click="addComparisonRow"
                >
                    <Plus class="size-4" />
                    Add row
                </Button>
            </div>

            <div
                class="overflow-hidden rounded-md border border-slate-200 bg-white"
            >
                <div
                    v-for="(row, rowIndex) in form.comparisonRows"
                    :key="rowIndex"
                    class="grid gap-2 border-b border-slate-200 p-3 last:border-b-0 md:grid-cols-[1fr_auto_auto]"
                >
                    <Input v-model="row.label" class="h-10" />
                    <div
                        class="flex flex-wrap items-center gap-3 text-sm text-slate-700"
                    >
                        <label
                            v-for="key in planKeys"
                            :key="`${rowIndex}-${key}`"
                            class="flex items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="row.tier_keys.includes(key)"
                                @change="toggleRowTier(row, key)"
                            />
                            {{ key }}
                        </label>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        @click="removeComparisonRow(rowIndex)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </form>
</template>
