{{-- Field metadata is resolved by App\View\Components\Cms\Field. --}}
@if ($isObject)
    <fieldset @class(['grid gap-4', 'rounded-lg border border-slate-200 bg-white p-4' => $level > 0])>
        @unless ($hideLabel)
            <legend class="px-1 text-sm font-semibold text-slate-950">{{ $label }}</legend>
        @endunless

        @foreach ($schema as $childKey => $childSchema)
            <x-cms.field
                :value="$value[$childKey] ?? $childSchema"
                :schema="$childSchema"
                :field-key="$childKey"
                :path="$path.'.'.$childKey"
                :name="$name.'['.$childKey.']'"
                :name-template="$nameTemplate.'['.$childKey.']'"
                :level="$level + 1"
            />
        @endforeach
    </fieldset>

@elseif ($isList)
    <div class="grid gap-3" x-data="repeater">
        @unless ($hideLabel)
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-semibold text-slate-950">{{ $label }}</span>
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="add()">
                    <x-icon name="plus" />
                    Add
                </x-ui.button>
            </div>
        @endunless

        <div class="grid gap-3" data-repeater-list>
            @foreach ($value as $index => $item)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3" data-repeater-item>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-slate-500">
                            {{ $label }} <span data-repeater-position>{{ $index + 1 }}</span>
                        </span>
                        <div class="flex gap-1">
                            <x-ui.button type="button" variant="ghost" size="icon-sm"
                                         x-on:click="move($event, -1)" aria-label="Move up">
                                <x-icon name="arrow-up" />
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="icon-sm"
                                         x-on:click="move($event, 1)" aria-label="Move down">
                                <x-icon name="arrow-down" />
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="icon-sm"
                                         x-on:click="remove($event)" aria-label="Remove">
                                <x-icon name="trash-2" class="text-red-600" />
                            </x-ui.button>
                        </div>
                    </div>

                    @if ($isObjectList)
                        <div class="grid gap-3">
                            @foreach ($listItemSchema as $childKey => $childSchema)
                                <x-cms.field
                                    :value="$item[$childKey] ?? $childSchema"
                                    :schema="$childSchema"
                                    :field-key="$childKey"
                                    :path="$path.'.'.$index.'.'.$childKey"
                                    :name="$name.'['.$index.']['.$childKey.']'"
                                    :name-template="$nameTemplate.'[__i__]['.$childKey.']'"
                                    :level="$level + 1"
                                />
                            @endforeach
                        </div>
                    @else
                        <x-cms.field
                            :value="$item"
                            :schema="$listItemSchema"
                            :field-key="$fieldKey"
                            :path="$path.'.'.$index"
                            :name="$name.'['.$index.']'"
                            :name-template="$nameTemplate.'[__i__]'"
                            :level="$level + 1"
                            hide-label
                        />
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Blueprint for new rows. Its fields carry only data-name, so nothing
             posts until the repeater assigns a real index. --}}
        <template data-repeater-template>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3" data-repeater-item>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-slate-500">
                        {{ $label }} <span data-repeater-position></span>
                    </span>
                    <div class="flex gap-1">
                        <x-ui.button type="button" variant="ghost" size="icon-sm"
                                     x-on:click="move($event, -1)" aria-label="Move up">
                            <x-icon name="arrow-up" />
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="icon-sm"
                                     x-on:click="move($event, 1)" aria-label="Move down">
                            <x-icon name="arrow-down" />
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="icon-sm"
                                     x-on:click="remove($event)" aria-label="Remove">
                            <x-icon name="trash-2" class="text-red-600" />
                        </x-ui.button>
                    </div>
                </div>

                @if ($isObjectList)
                    <div class="grid gap-3">
                        @foreach ($listItemSchema as $childKey => $childSchema)
                            <x-cms.field
                                :value="$blankItem($childSchema)"
                                :schema="$childSchema"
                                :field-key="$childKey"
                                :path="$path.'.new.'.$childKey"
                                name=""
                                :name-template="$nameTemplate.'[__i__]['.$childKey.']'"
                                :level="$level + 1"
                            />
                        @endforeach
                    </div>
                @else
                    <x-cms.field
                        :value="$blankItem($listItemSchema)"
                        :schema="$listItemSchema"
                        :field-key="$fieldKey"
                        :path="$path.'.new'"
                        name=""
                        :name-template="$nameTemplate.'[__i__]'"
                        :level="$level + 1"
                        hide-label
                    />
                @endif
            </div>
        </template>
    </div>

@elseif (is_bool($value))
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" data-name="{{ $nameTemplate }}" @if ($name) name="{{ $name }}" @endif value="0">
        <input type="checkbox" data-name="{{ $nameTemplate }}" @if ($name) name="{{ $name }}" @endif
               value="1" class="size-4" @checked($value)>
        {{ $label }}
    </label>
    <x-ui.input-error :name="$path" />

@elseif (is_int($value) || is_float($value))
    <div class="grid gap-1.5">
        @unless ($hideLabel)
            <x-ui.label :for="$inputId" class="text-xs">{{ $label }}</x-ui.label>
        @endunless
        <x-ui.input :id="$inputId" data-name="{{ $nameTemplate }}" :name="$name ?: null" type="number" step="any"
                    class="h-9" value="{{ $value }}" />
        <x-ui.input-error :name="$path" />
    </div>

@elseif ($fieldKey === 'icon')
    <div class="grid gap-1.5">
        @unless ($hideLabel)
            <x-ui.label :for="$inputId" class="text-xs">{{ $label }}</x-ui.label>
        @endunless
        <x-ui.select :id="$inputId" data-name="{{ $nameTemplate }}" :name="$name ?: null" class="h-9"
                     :options="$iconOptions()" :selected="$value" />
        <x-ui.input-error :name="$path" />
    </div>

@elseif ($isLongText)
    <div class="grid gap-1.5">
        @unless ($hideLabel)
            <x-ui.label :for="$inputId" class="text-xs">{{ $label }}</x-ui.label>
        @endunless
        <x-ui.textarea :id="$inputId" data-name="{{ $nameTemplate }}" :name="$name ?: null" rows="3">{{ $value }}</x-ui.textarea>
        <x-ui.input-error :name="$path" />
    </div>

@else
    <div class="grid gap-1.5">
        @unless ($hideLabel)
            <x-ui.label :for="$inputId" class="text-xs">{{ $label }}</x-ui.label>
        @endunless
        <x-ui.input :id="$inputId" data-name="{{ $nameTemplate }}" :name="$name ?: null" class="h-9"
                    value="{{ $value }}" :placeholder="$isUrl ? '/path or https://' : null" />
        <x-ui.input-error :name="$path" />
    </div>
@endif
