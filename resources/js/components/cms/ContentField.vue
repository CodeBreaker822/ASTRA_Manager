<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type JsonPrimitive = string | number | boolean | null;
type JsonValue = JsonPrimitive | JsonObject | JsonValue[];
type JsonObject = { [key: string]: JsonValue };

const props = withDefaults(
    defineProps<{
        modelValue: unknown;
        schema: unknown;
        fieldKey: string;
        path: string;
        errors: Record<string, string>;
        level?: number;
        hideLabel?: boolean;
    }>(),
    {
        level: 0,
        hideLabel: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: unknown];
}>();

const labelOverrides: Record<string, string> = {
    seo: 'Search engine preview',
    cta: 'Final call to action',
    faq: 'Frequently asked questions',
    faq_heading: 'FAQ heading',
    hero: 'Hero section',
    intro: 'Introduction',
    body: 'Description',
    eyebrow: 'Small heading',
    button_url: 'Button destination',
    primary_button_url: 'Primary button destination',
    secondary_button_url: 'Secondary button destination',
    online_button_url: 'Online button destination',
    desktop_button_url: 'Desktop button destination',
    home_url: 'Home page destination',
    aria_label: 'Accessibility label',
    mobile_open_label: 'Mobile menu accessibility label',
    seo_title_template: 'Article SEO title template',
    free_active_label: 'Label when free minutes are configured',
    free_fallback_label: 'Label when no free minutes are configured',
    upload_active_label: 'Label when upload pricing is configured',
    upload_fallback_label: 'Label when upload pricing is unavailable',
    live_active_label: 'Label when live pricing is configured',
    live_fallback_label: 'Label when live pricing is unavailable',
};

const sectionLabelOverrides: Record<string, string> = {
    audio_to_text: 'Audio to text',
    workspace_preview: 'Workspace preview',
    pricing_proof: 'Shared pricing facts',
    paths_intro: 'Workflow choices heading',
    benefits_intro: 'Benefits heading',
    requirements_intro: 'Requirements heading',
    feature_rows: 'Feature sections',
    feature_visual: 'Feature illustration labels',
    article_cta: 'Article call to action',
    plans_ui: 'Pricing-card labels',
};

const humanize = (key: string): string => {
    if (labelOverrides[key]) {
        return labelOverrides[key];
    }

    if (sectionLabelOverrides[key]) {
        return sectionLabelOverrides[key];
    }

    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
};

const label = computed(() => humanize(props.fieldKey));
const error = computed(() => props.errors[props.path]);
const isArray = computed(() => Array.isArray(props.modelValue));
const isObject = computed(
    () =>
        props.modelValue !== null &&
        typeof props.modelValue === 'object' &&
        !Array.isArray(props.modelValue),
);
const objectValue = computed(() => props.modelValue as JsonObject);
const objectSchema = computed(() => props.schema as JsonObject);
const listValue = computed(() => props.modelValue as JsonValue[]);
const listSchema = computed(() => props.schema as JsonValue[]);
const listItemSchema = computed(
    () => listSchema.value?.[0] ?? listValue.value?.[0] ?? '',
);
const isObjectList = computed(() => {
    const item = listItemSchema.value;

    return item !== null && typeof item === 'object' && !Array.isArray(item);
});
const isBoolean = computed(() => typeof props.modelValue === 'boolean');
const isNumber = computed(() => typeof props.modelValue === 'number');
const isUrl = computed(
    () =>
        props.fieldKey === 'url' ||
        props.fieldKey.endsWith('_url') ||
        props.fieldKey.endsWith('_href'),
);
const isLongText = computed(() => {
    const key = props.fieldKey;

    return (
        ['body', 'intro', 'description', 'answer', 'note', 'copyright'].some(
            (part) => key.includes(part),
        ) || String(props.modelValue ?? '').length > 110
    );
});
const inputId = computed(() =>
    `cms-${props.path}`.replace(/[^a-zA-Z0-9-_]/g, '-'),
);
const stringValue = computed({
    get: () => String(props.modelValue ?? ''),
    set: (value: string) => emit('update:modelValue', value),
});
const numberValue = computed({
    get: () => Number(props.modelValue ?? 0),
    set: (value: number) => emit('update:modelValue', value),
});
const booleanValue = computed({
    get: () => Boolean(props.modelValue),
    set: (value: boolean) => emit('update:modelValue', value),
});

const iconNames = [
    'Network',
    'Mic',
    'Languages',
    'FileAudio',
    'Sparkles',
    'FileSpreadsheet',
    'ShieldCheck',
    'Scissors',
    'Users',
    'Laptop',
    'Cpu',
    'HardDrive',
];

const emptyValue = (value: JsonValue, key = ''): JsonValue => {
    if (typeof value === 'string') {
        return key === 'icon' ? value : '';
    }

    if (typeof value === 'number') {
        return 0;
    }

    if (typeof value === 'boolean') {
        return false;
    }

    if (Array.isArray(value)) {
        return value.length ? [emptyValue(value[0])] : [];
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value).map(([childKey, childValue]) => [
                childKey,
                emptyValue(childValue, childKey),
            ]),
        );
    }

    return null;
};

const updateObject = (key: string, value: unknown) => {
    emit('update:modelValue', { ...objectValue.value, [key]: value });
};

const updateList = (index: number, value: unknown) => {
    const next = [...listValue.value];
    next[index] = value as JsonValue;
    emit('update:modelValue', next);
};

const addItem = () => {
    emit('update:modelValue', [
        ...listValue.value,
        emptyValue(listItemSchema.value),
    ]);
};

const removeItem = (index: number) => {
    if (listValue.value.length <= 1) {
        return;
    }

    emit(
        'update:modelValue',
        listValue.value.filter((_, itemIndex) => itemIndex !== index),
    );
};

const moveItem = (index: number, direction: -1 | 1) => {
    const destination = index + direction;

    if (destination < 0 || destination >= listValue.value.length) {
        return;
    }

    const next = [...listValue.value];
    [next[index], next[destination]] = [next[destination], next[index]];
    emit('update:modelValue', next);
};

const itemTitle = (item: JsonValue, index: number): string => {
    if (item && typeof item === 'object' && !Array.isArray(item)) {
        for (const key of ['title', 'question', 'label', 'name']) {
            if (typeof item[key] === 'string' && item[key].trim()) {
                return item[key];
            }
        }
    }

    return `${label.value} ${index + 1}`;
};

const fieldHint = computed(() => {
    if (props.path.endsWith('.seo.title')) {
        return 'Aim for 50–60 characters. This is the blue title in search results.';
    }

    if (props.path.endsWith('.seo.description')) {
        return 'Aim for 140–160 characters. Describe this page honestly and clearly.';
    }

    if (props.fieldKey.includes('template')) {
        return 'Keep placeholders inside braces, such as {title}, {minutes}, or {year}.';
    }

    if (isUrl.value) {
        return 'Use a site path such as /audio-to-text, an anchor such as #models, or a full https:// URL.';
    }

    return null;
});
</script>

<template>
    <div v-if="isObject" class="grid gap-4">
        <div
            v-if="!hideLabel"
            class="border-b border-slate-200 pb-2 text-sm font-semibold text-slate-900"
        >
            {{ label }}
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <ContentField
                v-for="(value, key) in objectValue"
                :key="key"
                :model-value="value"
                :schema="objectSchema[key] ?? value"
                :field-key="String(key)"
                :path="`${path}.${key}`"
                :errors="errors"
                :level="level + 1"
                @update:model-value="updateObject(String(key), $event)"
            />
        </div>
        <p v-if="error" class="text-xs font-medium text-red-600">
            {{ error }}
        </p>
    </div>

    <div v-else-if="isArray && isObjectList" class="grid gap-3 md:col-span-2">
        <div class="flex items-center justify-between gap-3">
            <Label v-if="!hideLabel" class="text-sm font-semibold">
                {{ label }}
            </Label>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :class="hideLabel ? 'ml-auto' : ''"
                @click="addItem"
            >
                <Plus class="size-4" />
                Add item
            </Button>
        </div>

        <details
            v-for="(item, index) in listValue"
            :key="index"
            class="group rounded-lg border border-slate-200 bg-slate-50"
            :open="index === 0"
        >
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-900"
            >
                <span class="min-w-0 truncate">{{
                    itemTitle(item, index)
                }}</span>
                <span class="text-xs font-normal text-slate-500">
                    {{ index + 1 }} / {{ listValue.length }}
                </span>
            </summary>
            <div class="border-t border-slate-200 bg-white p-4">
                <ContentField
                    :model-value="item"
                    :schema="listItemSchema"
                    :field-key="`${fieldKey}_${index + 1}`"
                    :path="`${path}.${index}`"
                    :errors="errors"
                    :level="level + 1"
                    hide-label
                    @update:model-value="updateList(index, $event)"
                />
                <div
                    class="mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="index === 0"
                        @click="moveItem(index, -1)"
                    >
                        <ArrowUp class="size-4" />
                        Move up
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="index === listValue.length - 1"
                        @click="moveItem(index, 1)"
                    >
                        <ArrowDown class="size-4" />
                        Move down
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-red-600 hover:text-red-700"
                        :disabled="listValue.length <= 1"
                        @click="removeItem(index)"
                    >
                        <Trash2 class="size-4" />
                        Remove
                    </Button>
                </div>
            </div>
        </details>
        <p v-if="error" class="text-xs font-medium text-red-600">
            {{ error }}
        </p>
    </div>

    <div v-else-if="isArray" class="grid gap-2 md:col-span-2">
        <div class="flex items-center justify-between gap-3">
            <Label v-if="!hideLabel" class="text-sm font-medium">
                {{ label }}
            </Label>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :class="hideLabel ? 'ml-auto' : ''"
                @click="addItem"
            >
                <Plus class="size-4" />
                Add item
            </Button>
        </div>
        <div
            v-for="(item, index) in listValue"
            :key="index"
            class="flex items-start gap-2"
        >
            <div class="min-w-0 flex-1">
                <textarea
                    v-if="String(item ?? '').length > 110"
                    :value="String(item ?? '')"
                    rows="3"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 transition outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                    @input="
                        updateList(
                            index,
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <Input
                    v-else
                    :model-value="String(item ?? '')"
                    class="h-10"
                    @update:model-value="updateList(index, String($event))"
                />
                <p
                    v-if="errors[`${path}.${index}`]"
                    class="mt-1 text-xs font-medium text-red-600"
                >
                    {{ errors[`${path}.${index}`] }}
                </p>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="index === 0"
                aria-label="Move item up"
                @click="moveItem(index, -1)"
            >
                <ArrowUp class="size-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="index === listValue.length - 1"
                aria-label="Move item down"
                @click="moveItem(index, 1)"
            >
                <ArrowDown class="size-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="text-red-600 hover:text-red-700"
                :disabled="listValue.length <= 1"
                aria-label="Remove item"
                @click="removeItem(index)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>
        <p v-if="error" class="text-xs font-medium text-red-600">
            {{ error }}
        </p>
    </div>

    <div v-else-if="isBoolean" class="grid gap-2">
        <label class="flex items-center gap-3 text-sm text-slate-800">
            <input
                v-model="booleanValue"
                type="checkbox"
                class="size-4 rounded border-slate-300 text-blue-600"
            />
            {{ label }}
        </label>
        <p v-if="error" class="text-xs font-medium text-red-600">
            {{ error }}
        </p>
    </div>

    <div v-else class="grid gap-2" :class="isLongText ? 'md:col-span-2' : ''">
        <div class="flex items-center justify-between gap-3">
            <Label v-if="!hideLabel" :for="inputId" class="text-sm font-medium">
                {{ label }}
            </Label>
            <span
                v-if="
                    path.endsWith('.seo.title') ||
                    path.endsWith('.seo.description')
                "
                class="text-xs text-slate-500"
            >
                {{ stringValue.length }}
            </span>
        </div>

        <select
            v-if="fieldKey === 'icon'"
            :id="inputId"
            v-model="stringValue"
            class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
        >
            <option v-for="icon in iconNames" :key="icon" :value="icon">
                {{ icon }}
            </option>
        </select>
        <textarea
            v-else-if="isLongText"
            :id="inputId"
            v-model="stringValue"
            rows="4"
            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-slate-900 transition outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
        />
        <Input
            v-else-if="isNumber"
            :id="inputId"
            v-model="numberValue"
            type="number"
            class="h-10"
        />
        <Input
            v-else
            :id="inputId"
            v-model="stringValue"
            type="text"
            :inputmode="isUrl ? 'url' : undefined"
            class="h-10"
        />

        <p v-if="fieldHint" class="text-xs leading-5 text-slate-500">
            {{ fieldHint }}
        </p>
        <p v-if="error" class="text-xs font-medium text-red-600">
            {{ error }}
        </p>
    </div>
</template>
