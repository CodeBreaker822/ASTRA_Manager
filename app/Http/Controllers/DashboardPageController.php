<?php

namespace App\Http\Controllers;

use App\Services\PageContentService;
use App\Support\CmsLabels;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DashboardPageController extends Controller
{
    public function edit(string $page, PageContentService $pages): View
    {
        Gate::authorize('cms.manage-pages');

        $definition = $this->definition($page);
        $defaults = $this->defaults($page);
        $content = $pages->pageOrDefault($page, $defaults);

        return view('dashboard.pages', [
            'pageKey' => $page,
            'title' => $definition['label'],
            'description' => $definition['description'],
            'previewUrl' => $definition['preview_url'],
            'pageOptions' => $this->pageOptions(),
            'content' => $content,
            'schema' => $defaults,
            'sections' => $this->sections($defaults),
            'seoTitle' => (string) data_get($content, 'seo.title', ''),
            'seoDescription' => (string) data_get($content, 'seo.description', ''),
        ]);
    }

    public function update(
        Request $request,
        string $page,
        PageContentService $pages,
    ): RedirectResponse {
        Gate::authorize('cms.manage-pages');

        $definition = $this->definition($page);
        $defaults = $this->defaults($page);
        $validated = $request->validate($this->rules($defaults));

        $pages->save($page, $validated['content'], $request->user()?->id);

        return back()->with('success', $definition['label'].' saved.');
    }

    /**
     * The editable sections of a page, with their display wording resolved.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array{key: string, label: string, description: string}>
     */
    private function sections(array $schema): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => CmsLabels::section($key),
            'description' => CmsLabels::sectionDescription($key),
        ], array_keys($schema));
    }

    /**
     * @return array{label: string, description: string, preview_url: string}
     */
    private function definition(string $page): array
    {
        $definition = config("marketing.cms.pages.{$page}");

        abort_unless(is_array($definition), 404);

        return [
            'label' => (string) ($definition['label'] ?? $page),
            'description' => (string) ($definition['description'] ?? ''),
            'preview_url' => (string) ($definition['preview_url'] ?? '/'),
        ];
    }

    /** @return array<string, mixed> */
    private function defaults(string $page): array
    {
        $defaults = config("marketing.pages.{$page}");

        abort_unless(is_array($defaults), 404);

        return $defaults;
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, preview_url: string}>
     */
    private function pageOptions(): array
    {
        $definitions = config('marketing.cms.pages', []);

        if (! is_array($definitions)) {
            return [];
        }

        $options = [];

        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                continue;
            }

            $options[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'description' => (string) ($definition['description'] ?? ''),
                'preview_url' => (string) ($definition['preview_url'] ?? '/'),
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, mixed>>
     */
    private function rules(array $schema): array
    {
        $rules = [
            'content' => ['required', $this->arrayRule($schema)],
        ];

        foreach ($schema as $key => $value) {
            $this->addRules($rules, "content.{$key}", $key, $value);
        }

        return $rules;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function addRules(
        array &$rules,
        string $path,
        string $key,
        mixed $schema,
    ): void {
        if (is_string($schema)) {
            $rules[$path] = [
                'required',
                'string',
                'max:'.$this->maxLength($path, $key),
                ...($this->isUrlKey($key) ? [$this->safeUrlRule()] : []),
                ...($key === 'icon' ? [Rule::in($this->iconNames())] : []),
            ];

            return;
        }

        if (is_bool($schema)) {
            $rules[$path] = ['required', 'boolean'];

            return;
        }

        if (is_int($schema) || is_float($schema)) {
            $rules[$path] = ['required', 'numeric'];

            return;
        }

        if (! is_array($schema)) {
            $rules[$path] = ['nullable'];

            return;
        }

        if (! array_is_list($schema)) {
            $rules[$path] = ['required', $this->arrayRule($schema)];

            foreach ($schema as $childKey => $value) {
                if (is_string($childKey)) {
                    $this->addRules(
                        $rules,
                        "{$path}.{$childKey}",
                        $childKey,
                        $value,
                    );
                }
            }

            return;
        }

        $rules[$path] = ['required', 'array', 'min:1', 'max:30'];
        $itemSchema = $schema[0] ?? '';

        if (is_array($itemSchema) && ! array_is_list($itemSchema)) {
            $rules["{$path}.*"] = ['required', $this->arrayRule($itemSchema)];

            foreach ($itemSchema as $childKey => $value) {
                if (is_string($childKey)) {
                    $this->addRules(
                        $rules,
                        "{$path}.*.{$childKey}",
                        $childKey,
                        $value,
                    );
                }
            }

            return;
        }

        $this->addRules($rules, "{$path}.*", $key, $itemSchema);
    }

    /** @param array<string, mixed> $schema */
    private function arrayRule(array $schema): string
    {
        return 'array:'.implode(',', array_keys($schema));
    }

    private function maxLength(string $path, string $key): int
    {
        if (str_ends_with($path, '.seo.title')) {
            return 80;
        }

        if (str_ends_with($path, '.seo.description')) {
            return 180;
        }

        if ($this->isUrlKey($key)) {
            return 500;
        }

        if (str_contains($key, 'title') || str_contains($key, 'label')) {
            return 240;
        }

        return 5000;
    }

    private function isUrlKey(string $key): bool
    {
        return $key === 'url'
            || str_ends_with($key, '_url')
            || str_ends_with($key, '_href');
    }

    private function safeUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (str_starts_with($value, '//')) {
                $fail('The :attribute cannot use a protocol-relative URL.');

                return;
            }

            foreach (['/', '#', 'https://', 'http://', 'mailto:', 'tel:'] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    return;
                }
            }

            $fail('The :attribute must be a site path or a safe web, email, or telephone URL.');
        };
    }

    /** @return array<int, string> */
    private function iconNames(): array
    {
        return [
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
    }
}
