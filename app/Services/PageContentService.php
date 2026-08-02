<?php

namespace App\Services;

use App\Models\PageContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PageContentService
{
    /**
     * @return array<string, mixed>
     */
    public function page(string $page): array
    {
        return Cache::rememberForever("page.{$page}.content", function () use ($page): array {
            $rows = $this->rows($page);

            if ($rows === []) {
                Log::error('CMS page content is missing.', [
                    'page' => $page,
                ]);

                throw new RuntimeException("CMS page content [{$page}] is not configured.");
            }

            return $rows;
        });
    }

    /**
     * Return database-managed content when present, with a source-controlled
     * fallback for newly deployed public pages before their production seeder
     * has run.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function pageOrDefault(string $page, array $default): array
    {
        return Cache::rememberForever(
            "page.{$page}.content",
            fn (): array => $this->mergeDefaults($default, $this->rows($page)),
        );
    }

    /**
     * Add newly shipped CMS fields without replacing values already edited in
     * the database. Lists are preserved; new keys are added to list items when
     * those items are structured objects.
     *
     * @param  array<string|int, mixed>  $defaults
     * @param  array<string|int, mixed>  $stored
     * @return array<string|int, mixed>
     */
    public function mergeDefaults(array $defaults, array $stored): array
    {
        if (array_is_list($defaults)) {
            if (! array_is_list($stored)) {
                return $defaults;
            }

            if ($stored === []) {
                return $defaults;
            }

            $itemDefaults = $defaults[0] ?? null;

            if (! is_array($itemDefaults) || array_is_list($itemDefaults)) {
                return $stored;
            }

            return array_map(
                fn (mixed $item): mixed => is_array($item)
                    ? $this->mergeDefaults($itemDefaults, $item)
                    : $item,
                $stored,
            );
        }

        $merged = $stored;

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored)) {
                $merged[$key] = $default;

                continue;
            }

            if (is_array($default) && is_array($stored[$key])) {
                $merged[$key] = $this->mergeDefaults($default, $stored[$key]);
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    public function save(string $page, array $sections, ?int $userId): void
    {
        foreach ($sections as $section => $content) {
            PageContent::query()->updateOrCreate(
                ['page' => $page, 'section' => $section],
                ['content' => $content, 'updated_by' => $userId],
            );
        }

        $this->forget($page);
    }

    public function forget(string $page): void
    {
        Cache::forget("page.{$page}.content");
    }

    /**
     * @return array<string, mixed>
     */
    private function contentArray(PageContent $content): array
    {
        $value = $content->getAttribute('content');

        if (! is_array($value)) {
            Log::error('CMS page section content is invalid.', [
                'page' => $content->page,
                'section' => $content->section,
                'content_id' => $content->id,
            ]);

            throw new RuntimeException("CMS page content [{$content->page}.{$content->section}] is invalid.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function rows(string $page): array
    {
        return PageContent::query()
            ->where('page', $page)
            ->get()
            ->mapWithKeys(fn (PageContent $content): array => [
                $content->section => $this->contentArray($content),
            ])
            ->all();
    }
}
