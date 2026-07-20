<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BlogPostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (File::glob(resource_path('blog/*.md')) ?: [] as $path) {
            [$frontMatter, $markdown] = $this->splitFrontMatter(File::get($path));
            $slug = (string) ($frontMatter['slug'] ?? Str::beforeLast(basename($path), '.md'));

            BlogPost::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => (string) ($frontMatter['title'] ?? Str::headline($slug)),
                    'excerpt' => (string) ($frontMatter['excerpt'] ?? ''),
                    'body_markdown' => $markdown,
                    'cover_path' => filled($frontMatter['cover'] ?? null) ? (string) $frontMatter['cover'] : null,
                    'status' => 'published',
                    'published_at' => filled($frontMatter['date'] ?? null) ? (string) $frontMatter['date'] : now(),
                ],
            );
        }
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontMatter(string $contents): array
    {
        if (! str_starts_with($contents, "---\n")) {
            return [[], $contents];
        }

        $end = strpos($contents, "\n---\n", 4);

        if ($end === false) {
            return [[], $contents];
        }

        $frontMatter = [];

        foreach (explode("\n", substr($contents, 4, $end - 4)) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $frontMatter[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return [$frontMatter, ltrim(substr($contents, $end + 5))];
    }
}
