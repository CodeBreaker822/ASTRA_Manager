<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Services\MarketingSeoService;
use App\Services\PageContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlogController extends Controller
{
    public function index(
        PageContentService $pages,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault(
            'blog',
            config('marketing.pages.blog', []),
        );

        $template = (string) data_get($content, 'index.reading_time_template', '{minutes} min read');

        $posts = $this->publishedPosts()
            ->get()
            ->map(fn (BlogPost $post): array => $this->withReadingTime($post->toPublicArray(), $template))
            ->values()
            ->all();

        return view('marketing.blog-index', [
            'content' => $content,
            'posts' => $posts,
            // The first post is the hero card; the rest fill the grid below it.
            'featuredPost' => $posts[0] ?? null,
            'remainingPosts' => array_slice($posts, 1),
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
    ): View {
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
        $template = (string) data_get($content, 'article.reading_time_template', '{minutes} min read');

        return view('marketing.blog-show', [
            'post' => $this->withReadingTime($post->toPublicArray(withBody: true), $template),
            'content' => $content,
            'article' => $content['article'],
            'relatedPosts' => $this->publishedPosts()
                ->whereKeyNot($post->getKey())
                ->limit(3)
                ->get()
                ->map(fn (BlogPost $related): array => $this->withReadingTime($related->toPublicArray(), $template))
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

    /**
     * Posts visible to the public, newest first.
     *
     * @return Builder<BlogPost>
     */
    private function publishedPosts(): Builder
    {
        return BlogPost::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at');
    }

    /**
     * Renders the CMS-managed "{minutes} min read" wording onto a post so the
     * view prints a finished string.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function withReadingTime(array $post, string $template): array
    {
        return [
            ...$post,
            'reading_time' => str_replace('{minutes}', (string) $post['reading_minutes'], $template),
        ];
    }
}
