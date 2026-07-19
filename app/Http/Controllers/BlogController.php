<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = collect(File::glob(resource_path('blog/*.md')) ?: [])
            ->map(fn (string $path): array => $this->postFromPath($path, false))
            ->sortByDesc('date')
            ->values()
            ->all();

        return Inertia::render('marketing/BlogIndex', [
            'posts' => $posts,
        ]);
    }

    public function show(string $slug): Response
    {
        $post = collect(File::glob(resource_path('blog/*.md')) ?: [])
            ->map(fn (string $path): array => $this->postFromPath($path, true))
            ->firstWhere('slug', $slug);

        if (! $post) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('marketing/BlogShow', [
            'post' => $post,
        ]);
    }

    /**
     * @return array{title: string, slug: string, date: string, excerpt: string, cover?: string, html?: string}
     */
    private function postFromPath(string $path, bool $withBody): array
    {
        [$frontMatter, $markdown] = $this->splitFrontMatter(File::get($path));
        $slug = (string) ($frontMatter['slug'] ?? Str::beforeLast(basename($path), '.md'));

        return array_filter([
            'title' => (string) ($frontMatter['title'] ?? Str::headline($slug)),
            'slug' => $slug,
            'date' => (string) ($frontMatter['date'] ?? ''),
            'excerpt' => (string) ($frontMatter['excerpt'] ?? ''),
            'cover' => (string) ($frontMatter['cover'] ?? ''),
            'html' => $withBody
                ? Str::markdown($markdown, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ])
                : null,
        ], fn (mixed $value): bool => $value !== null);
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
