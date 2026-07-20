<?php

namespace App\Services;

use App\Models\PageContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PageContentService
{
    /**
     * @return array<string, mixed>
     */
    public function page(string $page): array
    {
        return Cache::rememberForever("page.{$page}.content", function () use ($page): array {
            $fallback = $this->fallback($page);

            if (! Schema::hasTable('page_contents')) {
                return $fallback;
            }

            $rows = PageContent::query()
                ->where('page', $page)
                ->get()
                ->mapWithKeys(fn (PageContent $content): array => [
                    $content->section => $this->contentArray($content),
                ])
                ->all();

            if ($rows === []) {
                return $fallback;
            }

            return array_replace_recursive($fallback, $rows);
        });
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
    private function fallback(string $page): array
    {
        $pages = config('marketing.pages', []);
        $content = is_array($pages) ? ($pages[$page] ?? []) : [];

        return is_array($content) ? $content : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentArray(PageContent $content): array
    {
        $value = $content->getAttribute('content');

        return is_array($value) ? $value : [];
    }
}
