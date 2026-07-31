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
            $rows = PageContent::query()
                ->where('page', $page)
                ->get()
                ->mapWithKeys(fn (PageContent $content): array => [
                    $content->section => $this->contentArray($content),
                ])
                ->all();

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
}
