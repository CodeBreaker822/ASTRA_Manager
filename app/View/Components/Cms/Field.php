<?php

namespace App\View\Components\Cms;

use App\Support\CmsLabels;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * One editable field in the page CMS. Recursive: an object renders a fieldset
 * of child fields, a list renders a repeater of them, and anything scalar
 * renders the matching input.
 */
class Field extends Component
{
    /** Field keys whose content is long enough to want a textarea. */
    private const LONG_TEXT_KEYS = ['body', 'intro', 'description', 'answer', 'note', 'copyright'];

    /** Above this many characters a value gets a textarea regardless of key. */
    private const LONG_TEXT_LENGTH = 110;

    public string $label;

    public string $inputId;

    public bool $isList;

    public bool $isObject;

    public bool $isUrl;

    public bool $isLongText;

    public mixed $listItemSchema;

    public bool $isObjectList;

    public function __construct(
        public mixed $value,
        public mixed $schema,
        public string $fieldKey,
        public string $path,
        public string $name,
        // `name` is what posts (real indices). `nameTemplate` carries `__i__` so
        // the repeater can renumber rows after an add, remove, or move. They
        // only differ inside a repeatable list.
        public ?string $nameTemplate = null,
        public int $level = 0,
        public bool $hideLabel = false,
    ) {
        $this->nameTemplate ??= $this->name;

        $this->label = CmsLabels::humanize($fieldKey);
        $this->inputId = (string) preg_replace('/[^A-Za-z0-9\-_]/', '-', "cms-{$path}");

        $this->isList = is_array($value) && array_is_list($value);
        $this->isObject = is_array($value) && ! array_is_list($value);
        $this->isUrl = $fieldKey === 'url'
            || str_ends_with($fieldKey, '_url')
            || str_ends_with($fieldKey, '_href');
        $this->isLongText = $this->looksLongForm();

        $this->listItemSchema = $this->isList
            ? ($schema[0] ?? ($value[0] ?? ''))
            : null;
        $this->isObjectList = is_array($this->listItemSchema)
            && ! array_is_list($this->listItemSchema);
    }

    /**
     * An empty value of the same shape, used to seed a new repeater row.
     */
    public function blankItem(mixed $itemSchema): mixed
    {
        return match (true) {
            is_string($itemSchema) => '',
            is_bool($itemSchema) => false,
            is_int($itemSchema), is_float($itemSchema) => 0,
            default => $itemSchema,
        };
    }

    /**
     * @return array<string, string>
     */
    public function iconOptions(): array
    {
        return array_combine(CmsLabels::ICONS, CmsLabels::ICONS);
    }

    public function render(): View
    {
        return view('components.cms.field');
    }

    private function looksLongForm(): bool
    {
        foreach (self::LONG_TEXT_KEYS as $part) {
            if (str_contains($this->fieldKey, $part)) {
                return true;
            }
        }

        return mb_strlen(is_scalar($this->value) ? (string) $this->value : '') > self::LONG_TEXT_LENGTH;
    }
}
