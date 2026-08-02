<?php

namespace Database\Seeders;

use App\Models\PageContent;
use App\Services\PageContentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class PageContentSeeder extends Seeder
{
    public function run(): void
    {
        $pages = app(PageContentService::class);

        foreach (config('marketing.pages', []) as $page => $sections) {
            if (! is_string($page) || ! is_array($sections)) {
                continue;
            }

            foreach ($sections as $section => $content) {
                if (! is_string($section) || ! is_array($content)) {
                    continue;
                }

                $row = PageContent::query()->firstOrNew([
                    'page' => $page,
                    'section' => $section,
                ]);
                $stored = $row->exists ? $row->content : [];
                $merged = $pages->mergeDefaults($content, $stored);

                if (! $row->exists || $row->content !== $merged) {
                    $row->content = $merged;
                    $row->save();
                }
            }

            Cache::forget("page.{$page}.content");
        }
    }
}
