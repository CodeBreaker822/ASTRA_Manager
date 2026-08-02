<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SeoContentSeeder extends Seeder
{
    /**
     * Add missing marketing content and evergreen blog posts while preserving
     * content already edited in the CMS.
     * This production-safe entry point intentionally does not create users,
     * plans, permissions, or billing records.
     */
    public function run(): void
    {
        $this->call([
            PageContentSeeder::class,
            BlogPostSeeder::class,
        ]);
    }
}
