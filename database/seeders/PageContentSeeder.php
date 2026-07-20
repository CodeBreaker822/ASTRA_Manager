<?php

namespace Database\Seeders;

use App\Models\PageContent;
use Illuminate\Database\Seeder;

class PageContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('marketing.pages', []) as $page => $sections) {
            if (! is_string($page) || ! is_array($sections)) {
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
        }
    }
}
