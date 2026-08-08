@props(['name', 'checked' => false, 'value' => 1])

<input type="hidden" name="{{ $name }}" value="0">
<input
    type="checkbox"
    name="{{ $name }}"
    id="{{ $attributes->get('id', $name) }}"
    value="{{ $value }}"
    @checked(old($name, $checked))
    {{ $attributes->class('size-4 shrink-0 rounded-[4px] border border-slate-300 text-primary accent-primary shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50') }}
>
