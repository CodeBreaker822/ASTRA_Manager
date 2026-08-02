<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Services\MarketingSeoService;
use App\Services\PageContentService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlogController extends Controller
{
    public function index(
        PageContentService $pages,
        MarketingSeoService $seo,
    ): Response {
        $content = $pages->pageOrDefault(
            'blog',
            config('marketing.pages.blog', []),
        );

        return Inertia::render('marketing/BlogIndex', [
            'content' => $content,
            'posts' => BlogPost::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->get()
                ->map(fn (BlogPost $post): array => $post->toPublicArray())
                ->values(),
            'seo' => $seo->metadata(
                $content,
                route('blog.index'),
                $seo->blogIndexStructuredData($content),
            ),
        ]);
    }

    public function show(
        string $slug,
        PageContentService $pages,
        MarketingSeoService $seo,
    ): Response {
        $post = BlogPost::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('slug', $slug)
            ->first();

        if (! $post) {
            throw new NotFoundHttpException;
        }

        $content = $pages->pageOrDefault(
            'blog',
            config('marketing.pages.blog', []),
        );
        $seoTitleTemplate = (string) data_get($content, 'article.seo_title_template', '{title}');

        return Inertia::render('marketing/BlogShow', [
            'post' => $post->toPublicArray(withBody: true),
            'content' => $content,
            'relatedPosts' => BlogPost::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->whereKeyNot($post->getKey())
                ->orderByDesc('published_at')
                ->limit(3)
                ->get()
                ->map(fn (BlogPost $related): array => $related->toPublicArray())
                ->values(),
            'seo' => $seo->metadata(
                [
                    'seo' => [
                        'title' => str_replace('{title}', $post->title, $seoTitleTemplate),
                        'description' => (string) ($post->excerpt ?? ''),
                    ],
                ],
                route('blog.show', ['slug' => $post->slug]),
                $seo->blogPostStructuredData($post, $content),
                'article',
            ),
        ]);
    }
}
