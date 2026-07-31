<?php

namespace Database\Seeders;

use App\Models\PageContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class SeoMarketingPageContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['home', 'features', 'download'] as $page) {
            $sections = config("marketing.pages.{$page}", []);

            if (! is_array($sections)) {
                continue;
            }

            foreach ($sections as $section => $content) {
                if (! is_string($section) || ! is_array($content)) {
                    continue;
                }

                PageContent::query()->updateOrCreate(
                    ['page' => $page, 'section' => $section],
                    ['content' => $content],
                );
            }

            Cache::forget("page.{$page}.content");
        }
    }
}
