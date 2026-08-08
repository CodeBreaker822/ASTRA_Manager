<x-layouts.dashboard title="Pricing Manager">
    <form method="POST" action="{{ route('dashboard.pricing.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="sticky top-0 z-10 -mx-6 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-6 py-3 backdrop-blur">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">Pricing</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Free sets daily allowances. Pay as you go sets the dollar rates deducted from user credit balance.
                </p>
            </div>

            <x-ui.submit>
                <x-icon name="save" />
                Save
            </x-ui.submit>
        </div>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold text-slate-950">Public Page Copy</h2>
            <div class="grid gap-3 md:grid-cols-[14rem_1fr]">
                <div class="grid gap-1.5">
                    <x-ui.label for="pricing-eyebrow" class="text-xs">Eyebrow</x-ui.label>
                    <x-ui.input id="pricing-eyebrow" name="pricingContent[hero][eyebrow]" class="h-9"
                                value="{{ old('pricingContent.hero.eyebrow', $pricingContent['hero']['eyebrow'] ?? '') }}" />
                </div>
                <div class="grid gap-1.5">
                    <x-ui.label for="pricing-title" class="text-xs">Title</x-ui.label>
                    <x-ui.input id="pricing-title" name="pricingContent[hero][title]" class="h-9"
                                value="{{ old('pricingContent.hero.title', $pricingContent['hero']['title'] ?? '') }}" />
                </div>
                <div class="grid gap-1.5 md:col-span-2">
                    <x-ui.label for="pricing-intro" class="text-xs">Intro</x-ui.label>
                    <x-ui.textarea id="pricing-intro" name="pricingContent[hero][intro]" rows="3">{{ old('pricingContent.hero.intro', $pricingContent['hero']['intro'] ?? '') }}</x-ui.textarea>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold text-slate-950">Credit Packs</h2>
            <div class="overflow-x-auto pb-2">
                <div class="flex min-w-max gap-4">
                    @foreach ($tiers as $i => $tier)
                        <div class="w-[460px] shrink-0 rounded-md border border-slate-200 bg-white p-4">
                            <input type="hidden" name="tiers[{{ $i }}][key]" value="{{ $tier['key'] }}">
                            <input type="hidden" name="tiers[{{ $i }}][llm_price]" value="{{ $tier['llm_price'] }}">
                            <input type="hidden" name="tiers[{{ $i }}][monthly_price]" value="{{ $tier['monthly_price'] }}">
                            <input type="hidden" name="tiers[{{ $i }}][yearly_price]" value="{{ $tier['yearly_price'] }}">

                            <div class="mb-4 flex items-start justify-between gap-3 border-b border-slate-200 pb-3">
                                <div>
                                    <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase">{{ $tier['key'] }}</p>
                                    <h3 class="mt-1 text-base font-semibold text-slate-950">{{ $tier['name'] ?: 'Credit pack' }}</h3>
                                </div>
                                <label class="flex h-9 items-center gap-2 text-sm text-slate-700">
                                    <input type="hidden" name="tiers[{{ $i }}][featured]" value="0">
                                    <input type="checkbox" name="tiers[{{ $i }}][featured]" value="1" class="size-4"
                                           @checked($tier['featured'])>
                                    Featured
                                </label>
                            </div>

                            <div class="grid gap-2">
                                <label for="name-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Pack name</span>
                                    <x-ui.input id="name-{{ $tier['key'] }}" name="tiers[{{ $i }}][name]" class="h-9"
                                                value="{{ $tier['name'] }}" />
                                </label>

                                <label for="minutes-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Audio minutes included</span>
                                    <x-ui.input id="minutes-{{ $tier['key'] }}" name="tiers[{{ $i }}][minutes]" type="number"
                                                min="0" class="h-9" value="{{ $tier['minutes'] }}" />
                                </label>

                                @foreach ($rateFields as [$field, $label, $placeholder, $hint])
                                    <label for="{{ $field }}-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                        <span class="font-medium text-slate-600">{{ $label }}</span>
                                        <x-ui.input id="{{ $field }}-{{ $tier['key'] }}" name="tiers[{{ $i }}][{{ $field }}]"
                                                    type="number" min="0" step="any" placeholder="{{ $placeholder }}"
                                                    class="h-9 font-mono text-sm" value="{{ $tier[$field] }}" />
                                        <div class="col-span-2 text-xs text-slate-400">{!! $hint !!}</div>
                                    </label>
                                @endforeach

                                <label for="free-polish-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Free polishes each day</span>
                                    <x-ui.input id="free-polish-{{ $tier['key'] }}" name="tiers[{{ $i }}][free_polish_uses_per_day]"
                                                type="number" min="0" class="h-9" value="{{ $tier['free_polish_uses_per_day'] }}" />
                                </label>

                                <label for="free-summary-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Free summaries each day</span>
                                    <x-ui.input id="free-summary-{{ $tier['key'] }}" name="tiers[{{ $i }}][free_summary_uses_per_day]"
                                                type="number" min="0" class="h-9" value="{{ $tier['free_summary_uses_per_day'] }}" />
                                </label>

                                @foreach ($charRateFields as [$field, $label])
                                    <label for="{{ $field }}-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                        <span class="font-medium text-slate-600">{{ $label }}</span>
                                        <x-ui.input id="{{ $field }}-{{ $tier['key'] }}" name="tiers[{{ $i }}][{{ $field }}]"
                                                    type="number" min="0" step="any" placeholder="0.000005"
                                                    class="h-9 font-mono text-sm" value="{{ $tier[$field] }}" />
                                        <div class="col-span-2 text-xs text-slate-400">
                                            Stored per character. Example: <strong>0.000005</strong> displays as $0.005 per 1,000 characters.
                                        </div>
                                    </label>
                                @endforeach

                                <label for="label-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Price shown to customers</span>
                                    <x-ui.input id="label-{{ $tier['key'] }}" name="tiers[{{ $i }}][price_label]" class="h-9"
                                                value="{{ $tier['price_label'] }}" />
                                </label>

                                <label for="tagline-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Short description</span>
                                    <x-ui.input id="tagline-{{ $tier['key'] }}" name="tiers[{{ $i }}][tagline]" class="h-9"
                                                value="{{ $tier['tagline'] }}" />
                                </label>

                                <label for="cta-{{ $tier['key'] }}" class="grid grid-cols-[9.5rem_1fr] items-center gap-3 text-sm">
                                    <span class="font-medium text-slate-600">Buy button text</span>
                                    <x-ui.input id="cta-{{ $tier['key'] }}" name="tiers[{{ $i }}][cta]" class="h-9"
                                                value="{{ $tier['cta'] }}" />
                                </label>

                                <div class="grid grid-cols-[9.5rem_1fr] gap-3 text-sm">
                                    <span class="pt-2 font-medium text-slate-600">What customers get</span>
                                    <div class="grid gap-2">
                                        @foreach ($tier['features'] as $featureIndex => $feature)
                                            <x-ui.input name="tiers[{{ $i }}][features][{{ $featureIndex }}]" class="h-9"
                                                        value="{{ $feature }}" aria-label="Feature {{ $featureIndex + 1 }}" />
                                        @endforeach
                                    </div>
                                </div>

                                <div class="grid grid-cols-[9.5rem_1fr] gap-3 text-sm">
                                    <span class="pt-2 font-medium text-slate-600">Allowed features</span>
                                    <div class="grid gap-2">
                                        @foreach ($entitlementFields as [$key, $label])
                                            <label class="flex items-center gap-2 text-slate-700">
                                                <input type="hidden" name="tiers[{{ $i }}][entitlements][{{ $key }}]" value="0">
                                                <input type="checkbox" name="tiers[{{ $i }}][entitlements][{{ $key }}]" value="1"
                                                       class="size-4" @checked($tier['entitlements'][$key])>
                                                {{ $label }}
                                            </label>
                                        @endforeach

                                        <div class="flex flex-wrap gap-3 pt-1">
                                            @foreach ($exportFormats as $format)
                                                <label class="flex items-center gap-2 text-slate-700 uppercase">
                                                    <input type="checkbox" name="tiers[{{ $i }}][entitlements][exports][]"
                                                           value="{{ $format }}" class="size-4"
                                                           @checked(in_array($format, $tier['entitlements']['exports'], true))>
                                                    {{ $format }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <x-ui.input-error name="tiers.{{ $i }}.name" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold text-slate-950">FAQ</h2>
            <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                @foreach ($pricingContent['faq'] ?? [] as $index => $item)
                    <div class="grid gap-2 border-b border-slate-200 p-3 last:border-b-0 md:grid-cols-[18rem_1fr]">
                        <x-ui.input name="pricingContent[faq][{{ $index }}][question]" aria-label="FAQ question"
                                    class="h-9" value="{{ $item['question'] }}" />
                        <x-ui.textarea name="pricingContent[faq][{{ $index }}][answer]" rows="2"
                                       aria-label="FAQ answer">{{ $item['answer'] }}</x-ui.textarea>
                    </div>
                @endforeach
            </div>
        </section>

        <section
            class="space-y-3"
            x-data="{
                rows: {{ Js::from(collect($comparisonRows)->map(fn ($row) => [
                    'label' => $row['label'],
                    'tier_keys' => array_values((array) $row['tier_keys']),
                ])->values()) }},
                add() {
                    this.rows.push({ label: '', tier_keys: [] });
                },
                remove(index) {
                    this.rows.splice(index, 1);
                },
            }"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-950">Comparison</h2>
                <x-ui.button type="button" variant="outline" x-on:click="add()">
                    <x-icon name="plus" />
                    Add row
                </x-ui.button>
            </div>

            <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <template x-for="(row, rowIndex) in rows" :key="rowIndex">
                    <div class="grid gap-2 border-b border-slate-200 p-3 last:border-b-0 md:grid-cols-[1fr_auto_auto]">
                        <input
                            type="text"
                            x-model="row.label"
                            x-bind:name="`comparisonRows[${rowIndex}][label]`"
                            aria-label="Comparison label"
                            class="flex h-10 w-full min-w-0 rounded-lg border border-slate-200 bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-100"
                        >
                        <div class="flex flex-wrap items-center gap-3 text-sm text-slate-700">
                            @foreach ($planKeys as $key)
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        value="{{ $key }}"
                                        class="size-4"
                                        x-bind:name="`comparisonRows[${rowIndex}][tier_keys][]`"
                                        x-bind:checked="row.tier_keys.includes('{{ $key }}')"
                                        x-on:change="
                                            row.tier_keys = $event.target.checked
                                                ? [...row.tier_keys, '{{ $key }}']
                                                : row.tier_keys.filter((item) => item !== '{{ $key }}')
                                        "
                                    >
                                    {{ $key }}
                                </label>
                            @endforeach
                        </div>
                        <x-ui.button type="button" variant="outline" size="icon" x-on:click="remove(rowIndex)"
                                     aria-label="Remove row">
                            <x-icon name="trash-2" />
                        </x-ui.button>
                    </div>
                </template>
            </div>
        </section>
    </form>
</x-layouts.dashboard>
