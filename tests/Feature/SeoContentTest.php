<?php

use App\Models\BlogPost;
use App\Models\PageContent;
use Database\Seeders\SeoContentSeeder;
use Inertia\Testing\AssertableInertia;

test('production SEO seeder publishes idempotent audio-to-text pages and guides', function () {
    $this->seed(SeoContentSeeder::class);

    expect(PageContent::query()
        ->where('page', 'audio_to_text')
        ->where('section', 'hero')
        ->firstOrFail()
        ->content['title'])
        ->toBe('Convert audio to text with JERVA')
        ->and(PageContent::query()
            ->where('page', 'blog')
            ->where('section', 'hero')
            ->exists())
        ->toBeTrue()
        ->and(BlogPost::query()
            ->where('status', 'published')
            ->where('slug', 'how-to-convert-audio-to-text')
            ->exists())
        ->toBeTrue()
        ->and(BlogPost::query()->where('status', 'published')->count())
        ->toBeGreaterThanOrEqual(8);

    $pageCount = PageContent::query()->count();
    $postIds = BlogPost::query()->orderBy('slug')->pluck('id', 'slug')->all();
    $editedPost = BlogPost::query()
        ->where('slug', 'how-to-convert-audio-to-text')
        ->firstOrFail();
    $editedPost->update(['title' => 'Dashboard-edited audio-to-text guide']);

    $this->seed(SeoContentSeeder::class);

    expect(PageContent::query()->count())->toBe($pageCount)
        ->and(BlogPost::query()->orderBy('slug')->pluck('id', 'slug')->all())
        ->toBe($postIds)
        ->and($editedPost->refresh()->title)
        ->toBe('Dashboard-edited audio-to-text guide');
});

test('audio-to-text landing page exposes focused content and structured data', function () {
    $this->withoutVite();
    $this->seed(SeoContentSeeder::class);

    $response = $this->get(route('audio-to-text'));

    $response
        ->assertOk()
        ->assertSee('<title data-inertia="">Audio to Text Converter Online &amp; Offline | JERVA</title>', escape: false)
        ->assertSee('<link data-inertia="canonical" rel="canonical" href="'.route('audio-to-text').'">', escape: false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/AudioToText')
            ->where('content.hero.title', 'Convert audio to text with JERVA')
            ->where('seo.canonical_url', route('audio-to-text'))
            ->where('seo.structured_data', fn ($data): bool => collect(collect($data)->get('@graph', []))
                ->contains(fn ($item): bool => data_get($item, '@type') === 'FAQPage'))
        );
});

test('blog pages expose canonical article metadata and related internal links', function () {
    $this->withoutVite();
    $this->seed(SeoContentSeeder::class);

    $post = BlogPost::query()
        ->where('slug', 'how-to-convert-audio-to-text')
        ->firstOrFail();

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/BlogIndex')
            ->where('content.hero.eyebrow', 'Audio-to-text guides')
            ->where('seo.canonical_url', route('blog.index'))
            ->has('posts', fn (AssertableInertia $posts) => $posts
                ->where('0.slug', 'how-to-convert-audio-to-text')
                ->etc())
        );

    $response = $this->get(route('blog.show', ['slug' => $post->slug]));

    $response
        ->assertOk()
        ->assertSee('<meta data-inertia="og:type" property="og:type" content="article">', escape: false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/BlogShow')
            ->where('post.slug', $post->slug)
            ->where('seo.type', 'article')
            ->where('seo.canonical_url', route('blog.show', ['slug' => $post->slug]))
            ->where('seo.structured_data', fn ($data): bool => collect(collect($data)->get('@graph', []))
                ->contains(fn ($item): bool => data_get($item, '@type') === 'BlogPosting'))
            ->has('relatedPosts', 3)
        );
});

test('sitemap includes the focused landing page and all seeded guides', function () {
    $this->seed(SeoContentSeeder::class);

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('audio-to-text'), escape: false)
        ->assertSee(route('blog.show', ['slug' => 'how-to-convert-audio-to-text']), escape: false)
        ->assertSee(route('blog.show', ['slug' => 'how-to-convert-mp3-to-text']), escape: false);
});
