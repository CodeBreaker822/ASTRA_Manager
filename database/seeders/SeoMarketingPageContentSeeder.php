<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SeoMarketingPageContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PageContentSeeder::class);
    }
}
