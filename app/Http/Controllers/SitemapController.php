<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\PageContent;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pageDates = PageContent::query()
            ->whereIn('page', ['home', 'audio_to_text', 'features', 'pricing', 'download', 'blog'])
            ->selectRaw('page, MAX(updated_at) as last_modified')
            ->groupBy('page')
            ->pluck('last_modified', 'page');

        $posts = BlogPost::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->get(['slug', 'published_at', 'updated_at']);

        $blogLastModified = collect([
            $pageDates->get('blog'),
            $posts
                ->map(fn (BlogPost $post) => $post->updated_at->max($post->published_at))
                ->max(),
        ])
            ->filter()
            ->map(fn (mixed $date) => Carbon::parse($date))
            ->max();

        $urls = [
            $this->url(route('home'), $pageDates->get('home'), 'weekly', '1.0'),
            $this->url(route('audio-to-text'), $pageDates->get('audio_to_text'), 'weekly', '0.9'),
            $this->url(route('features'), $pageDates->get('features'), 'monthly', '0.8'),
            $this->url(route('price'), $pageDates->get('pricing'), 'weekly', '0.8'),
            $this->url(route('download'), $pageDates->get('download'), 'weekly', '0.9'),
            $this->url(route('blog.index'), $blogLastModified, 'weekly', '0.7'),
            ...$posts->map(fn (BlogPost $post): array => $this->url(
                route('blog.show', ['slug' => $post->slug]),
                $post->updated_at->max($post->published_at),
                'monthly',
                '0.6',
            ))->all(),
        ];

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * @return array{loc: string, lastmod: string|null, changefreq: string, priority: string}
     */
    private function url(string $location, mixed $lastModified, string $changeFrequency, string $priority): array
    {
        return [
            'loc' => $location,
            'lastmod' => $lastModified ? Carbon::parse($lastModified)->toAtomString() : null,
            'changefreq' => $changeFrequency,
            'priority' => $priority,
        ];
    }
}
