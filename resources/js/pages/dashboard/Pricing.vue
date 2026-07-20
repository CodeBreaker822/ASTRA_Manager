<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Save, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type TierKey = 'free' | 'pro' | 'team';

type Tier = {
    key: TierKey;
    name: string;
    tagline: string;
    monthly_price: number | null;
    yearly_price: number | null;
    price_label: string;
    minutes: number;
    cta: string;
    featured: boolean;
    features: string[];
    entitlements: {
        upload?: boolean;
        live?: boolean;
        polish?: boolean;
        summarize?: boolean;
        team?: boolean;
        exports?: string[];
    };
};

type ComparisonRow = {
    label: string;
    tier_keys: TierKey[];
};

const props = defineProps<{
    tiers: Tier[];
    comparisonRows: ComparisonRow[];
    pricingContent: {
        hero: {
            eyebrow: string;
            title: string;
            intro: string;
        };
        faq: Array<{
            question: string;
            answer: string;
        }>;
    };
}>();

defineOptions({
    layout: DashboardLayout,
});

const pricingContent = {
    hero: {
        eyebrow: props.pricingContent?.hero?.eyebrow ?? '',
        title: props.pricingContent?.hero?.title ?? '',
        intro: props.pricingContent?.hero?.intro ?? '',
    },
    faq: Array.isArray(props.pricingContent?.faq)
        ? props.pricingContent.faq.map((item) => ({
              question: item.question ?? '',
              answer: item.answer ?? '',
          }))
        : [],
};

const form = useForm({
    tiers: props.tiers.map((tier) => ({
        ...tier,
        features: [...tier.features, '', '', '', ''].slice(0, 6),
        entitlements: {
            upload: Boolean(tier.entitlements.upload),
            live: Boolean(tier.entitlements.live),
            polish: Boolean(tier.entitlements.polish),
            summarize: Boolean(tier.entitlements.summarize),
            team: Boolean(tier.entitlements.team),
            exports: [...(tier.entitlements.exports ?? [])],
        },
    })),
    comparisonRows: props.comparisonRows.map((row) => ({
        label: row.label,
        tier_keys: [...row.tier_keys],
    })),
    pricingContent,
});

const exportFormats = ['txt', 'docx', 'xlsx'];
const planKeys: TierKey[] = ['free', 'pro', 'team'];
const entitlementKeys: Array<Exclude<keyof Tier['entitlements'], 'exports'>> = [
    'upload',
    'live',
    'polish',
    'summarize',
    'team',
];

const toggleEntitlement = (
    tier: Tier,
    key: Exclude<keyof Tier['entitlements'], 'exports'>,
) => {
    tier.entitlements[key] = !tier.entitlements[key];
};

const toggleExport = (tier: Tier, format: string) => {
    const exports = tier.entitlements.exports ?? [];
    tier.entitlements.exports = exports.includes(format)
        ? exports.filter((item) => item !== format)
        : [...exports, format];
};

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

const submit = () => {
    form.put('/dashboard/pricing', { preserveScroll: true });
};
</script>

<template>
    <Head title="Pricing Manager" />

    <form class="space-y-6" @submit.prevent="submit">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">Pricing</h1>
                <p class="mt-1 text-sm text-slate-700">
                    Keep public plan copy aligned with active checkout amounts.
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                <Save v-else class="size-4" />
                Save
            </Button>
        </div>

        <div
            class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900"
        >
            Displayed prices are marketing copy. Actual PayMongo checkout
            amounts come from `PAYMONGO_*_AMOUNT` env vars - keep them in sync.
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-950">
                Pricing Page Copy
            </h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pricing-eyebrow">Eyebrow</Label>
                    <Input
                        id="pricing-eyebrow"
                        v-model="form.pricingContent.hero.eyebrow"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="pricing-title">Title</Label>
                    <Input
                        id="pricing-title"
                        v-model="form.pricingContent.hero.title"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="pricing-intro">Intro</Label>
                    <textarea
                        id="pricing-intro"
                        v-model="form.pricingContent.hero.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
            </div>

            <div class="mt-5 grid gap-3">
                <article
                    v-for="(item, index) in form.pricingContent.faq"
                    :key="index"
                    class="rounded-lg border border-slate-200 p-3"
                >
                    <Input v-model="item.question" class="h-10" />
                    <textarea
                        v-model="item.answer"
                        rows="3"
                        class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </article>
            </div>
        </section>

        <section class="grid gap-4">
            <article
                v-for="(tier, tierIndex) in form.tiers"
                :key="tier.key"
                class="rounded-lg border border-slate-200 bg-white p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-slate-950">
                        {{ tier.name || tier.key }}
                    </h2>
                    <label
                        class="flex items-center gap-2 text-sm text-slate-700"
                    >
                        <input
                            v-model="tier.featured"
                            type="checkbox"
                            class="size-4"
                        />
                        Featured
                    </label>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label :for="`name-${tier.key}`">Name</Label>
                        <Input
                            :id="`name-${tier.key}`"
                            v-model="tier.name"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`tagline-${tier.key}`">Tagline</Label>
                        <Input
                            :id="`tagline-${tier.key}`"
                            v-model="tier.tagline"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`monthly-${tier.key}`"
                            >Monthly price</Label
                        >
                        <Input
                            :id="`monthly-${tier.key}`"
                            v-model.number="tier.monthly_price"
                            type="number"
                            min="0"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`yearly-${tier.key}`">Yearly price</Label>
                        <Input
                            :id="`yearly-${tier.key}`"
                            v-model.number="tier.yearly_price"
                            type="number"
                            min="0"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`label-${tier.key}`">Price label</Label>
                        <Input
                            :id="`label-${tier.key}`"
                            v-model="tier.price_label"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`minutes-${tier.key}`">Minutes</Label>
                        <Input
                            :id="`minutes-${tier.key}`"
                            v-model.number="tier.minutes"
                            type="number"
                            min="0"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2 md:col-span-2">
                        <Label :for="`cta-${tier.key}`">CTA</Label>
                        <Input
                            :id="`cta-${tier.key}`"
                            v-model="tier.cta"
                            class="h-11"
                        />
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-4">
                        <div class="text-sm font-semibold text-slate-950">
                            Features
                        </div>
                        <div class="mt-3 grid gap-2">
                            <Input
                                v-for="(_, featureIndex) in tier.features"
                                :key="`${tier.key}-feature-${featureIndex}`"
                                v-model="tier.features[featureIndex]"
                                class="h-10"
                            />
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-4">
                        <div class="text-sm font-semibold text-slate-950">
                            Entitlements
                        </div>
                        <div class="mt-3 grid gap-2 text-sm text-slate-700">
                            <label
                                v-for="feature in entitlementKeys"
                                :key="`${tier.key}-${feature}`"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="
                                        Boolean(tier.entitlements[feature])
                                    "
                                    @change="toggleEntitlement(tier, feature)"
                                />
                                {{ feature }}
                            </label>
                        </div>

                        <div class="mt-4 text-sm font-semibold text-slate-950">
                            Exports
                        </div>
                        <div
                            class="mt-2 flex flex-wrap gap-3 text-sm text-slate-700"
                        >
                            <label
                                v-for="format in exportFormats"
                                :key="`${tier.key}-${format}`"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="
                                        tier.entitlements.exports?.includes(
                                            format,
                                        )
                                    "
                                    @change="toggleExport(tier, format)"
                                />
                                {{ format }}
                            </label>
                        </div>
                    </div>
                </div>

                <div
                    v-if="form.errors[`tiers.${tierIndex}.name`]"
                    class="mt-3 text-sm text-red-600"
                >
                    {{ form.errors[`tiers.${tierIndex}.name`] }}
                </div>
            </article>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-slate-950">
                    Comparison
                </h2>
                <Button
                    type="button"
                    variant="outline"
                    @click="addComparisonRow"
                >
                    <Plus class="size-4" />
                    Add row
                </Button>
            </div>

            <div class="mt-4 grid gap-3">
                <div
                    v-for="(row, rowIndex) in form.comparisonRows"
                    :key="rowIndex"
                    class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-[1fr_auto_auto]"
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
