<?php

namespace App\Services;

use App\Models\BlogPost;
use Illuminate\Support\Str;

class MarketingSeoService
{
    public function __construct(private PageContentService $pages) {}

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $structuredData
     * @return array{
     *     title: string,
     *     description: string,
     *     canonical_url: string,
     *     image_url: string,
     *     site_name: string,
     *     type: string,
     *     robots: string,
     *     structured_data: array<string, mixed>|null
     * }
     */
    public function metadata(
        array $content,
        string $canonicalUrl,
        ?array $structuredData = null,
        string $type = 'website',
    ): array {
        $seo = is_array($content['seo'] ?? null) ? $content['seo'] : [];
        $site = $this->site();
        $image = trim((string) data_get($site, 'seo.default_image_url', '/JervaLogo.png'));

        return [
            'title' => trim((string) ($seo['title'] ?? data_get($site, 'brand.name', config('app.name')))),
            'description' => trim((string) ($seo['description'] ?? '')),
            'canonical_url' => $canonicalUrl,
            'image_url' => Str::startsWith($image, ['http://', 'https://'])
                ? $image
                : url('/'.ltrim($image, '/')),
            'site_name' => trim((string) data_get($site, 'seo.site_name', data_get($site, 'brand.name', config('app.name')))),
            'type' => $type,
            'robots' => 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'structured_data' => $structuredData,
        ];
    }

    /**
     * @param  array{currency: string, free_minutes_per_day: int|null, upload_price_per_hour: float|null, live_price_per_hour: float|null}  $pricing
     * @return array<string, mixed>
     */
    public function homeStructuredData(array $pricing): array
    {
        $site = $this->site();
        $schema = is_array($site['schema'] ?? null) ? $site['schema'] : [];
        $organizationName = (string) ($schema['organization_name'] ?? data_get($site, 'brand.name', config('app.name')));
        $image = (string) data_get($site, 'seo.default_image_url', '/JervaLogo.png');
        $logo = Str::startsWith($image, ['http://', 'https://'])
            ? $image
            : url('/'.ltrim($image, '/'));
        $offerDescription = filled($pricing['free_minutes_per_day'])
            ? str_replace(
                '{minutes}',
                (string) $pricing['free_minutes_per_day'],
                (string) ($schema['free_offer_template'] ?? ''),
            )
            : (string) ($schema['free_offer_fallback'] ?? '');

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => route('home').'#organization',
                    'name' => $organizationName,
                    'url' => route('home'),
                    'logo' => $logo,
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => route('home').'#website',
                    'name' => (string) ($schema['website_name'] ?? $organizationName),
                    'url' => route('home'),
                    'publisher' => ['@id' => route('home').'#organization'],
                ],
                [
                    '@type' => 'WebApplication',
                    '@id' => route('home').'#web-application',
                    'name' => (string) ($schema['application_name'] ?? $organizationName),
                    'url' => route('home'),
                    'applicationCategory' => (string) ($schema['application_category'] ?? ''),
                    'operatingSystem' => (string) ($schema['application_operating_system'] ?? ''),
                    'description' => (string) ($schema['home_application_description'] ?? ''),
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => 0,
                        'priceCurrency' => $pricing['currency'],
                        'description' => $offerDescription,
                    ],
                    'featureList' => array_values((array) ($schema['home_feature_list'] ?? [])),
                    'publisher' => ['@id' => route('home').'#organization'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function audioToTextStructuredData(array $content): array
    {
        $seo = is_array($content['seo'] ?? null) ? $content['seo'] : [];
        $schema = $this->siteSchema();
        $breadcrumb = $this->breadcrumbStructuredData(
            (string) data_get($content, 'schema.breadcrumb_label', ''),
            route('audio-to-text'),
        );
        unset($breadcrumb['@context']);

        $graph = [
            [
                '@type' => 'WebPage',
                '@id' => route('audio-to-text').'#webpage',
                'url' => route('audio-to-text'),
                'name' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'isPartOf' => ['@id' => route('home').'#website'],
                'about' => ['@id' => route('audio-to-text').'#application'],
            ],
            [
                '@type' => 'WebApplication',
                '@id' => route('audio-to-text').'#application',
                'name' => (string) ($schema['audio_application_name'] ?? ''),
                'url' => route('audio-to-text'),
                'applicationCategory' => (string) ($schema['application_category'] ?? ''),
                'operatingSystem' => (string) ($schema['application_operating_system'] ?? ''),
                'description' => (string) ($schema['audio_application_description'] ?? ''),
                'featureList' => array_values((array) ($schema['audio_feature_list'] ?? [])),
            ],
            $breadcrumb,
        ];

        $faq = $this->faqStructuredData($content['faq'] ?? null);

        if ($faq !== null) {
            $graph[] = $faq;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function blogIndexStructuredData(array $content): array
    {
        $seo = is_array($content['seo'] ?? null) ? $content['seo'] : [];
        $breadcrumb = $this->breadcrumbStructuredData(
            (string) data_get($content, 'schema.breadcrumb_label', ''),
            route('blog.index'),
        );
        unset($breadcrumb['@context']);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'CollectionPage',
                    '@id' => route('blog.index').'#collection',
                    'url' => route('blog.index'),
                    'name' => (string) ($seo['title'] ?? ''),
                    'description' => (string) ($seo['description'] ?? ''),
                    'isPartOf' => ['@id' => route('home').'#website'],
                ],
                $breadcrumb,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function blogPostStructuredData(BlogPost $post, array $content): array
    {
        $url = route('blog.show', ['slug' => $post->slug]);
        $public = $post->toPublicArray();
        $cover = (string) ($public['cover_url'] ?? '');
        $site = $this->site();
        $schema = $this->siteSchema();
        $defaultImage = (string) data_get($site, 'seo.default_image_url', '/JervaLogo.png');
        $defaultImageUrl = Str::startsWith($defaultImage, ['http://', 'https://'])
            ? $defaultImage
            : url('/'.ltrim($defaultImage, '/'));
        $image = filled($cover)
            ? (Str::startsWith($cover, ['http://', 'https://']) ? $cover : url($cover))
            : $defaultImageUrl;

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BlogPosting',
                    '@id' => $url.'#article',
                    'headline' => $post->title,
                    'description' => (string) ($post->excerpt ?? ''),
                    'url' => $url,
                    'mainEntityOfPage' => $url,
                    'datePublished' => $post->published_at?->toAtomString(),
                    'dateModified' => $post->updated_at?->toAtomString(),
                    'image' => $image,
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => (string) ($schema['organization_name'] ?? data_get($site, 'brand.name', config('app.name'))),
                        'url' => route('home'),
                        'logo' => [
                            '@type' => 'ImageObject',
                            'url' => $defaultImageUrl,
                        ],
                    ],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => (string) ($schema['home_breadcrumb_label'] ?? ''),
                            'item' => route('home'),
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => (string) data_get($content, 'article.blog_label', ''),
                            'item' => route('blog.index'),
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 3,
                            'name' => $post->title,
                            'item' => $url,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function breadcrumbStructuredData(string $name, string $url): array
    {
        $schema = $this->siteSchema();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => (string) ($schema['home_breadcrumb_label'] ?? ''),
                    'item' => route('home'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $name,
                    'item' => $url,
                ],
            ],
        ];
    }

    /**
     * @param  array{available: bool, platform: string, size: string|null, published_at: string|null, published_at_iso: string|null, download_url: string|null}  $release
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function downloadStructuredData(array $release, array $content): array
    {
        $schema = $this->siteSchema();
        $application = [
            '@type' => 'SoftwareApplication',
            '@id' => route('download').'#windows-application',
            'name' => (string) ($schema['download_application_name'] ?? ''),
            'url' => route('download'),
            'applicationCategory' => (string) ($schema['application_category'] ?? ''),
            'operatingSystem' => $release['platform'],
            'description' => (string) ($schema['download_application_description'] ?? ''),
            'offers' => [
                '@type' => 'Offer',
                'price' => 0,
                'priceCurrency' => (string) ($schema['offer_currency'] ?? ''),
                'availability' => $release['available']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
            'featureList' => array_values((array) ($schema['download_feature_list'] ?? [])),
        ];

        if ($release['available'] && filled($release['download_url'])) {
            $application['downloadUrl'] = $release['download_url'];
        }

        if (filled($release['published_at_iso'])) {
            $application['datePublished'] = $release['published_at_iso'];
        }

        if (filled($release['size'])) {
            $application['fileSize'] = $release['size'];
        }

        $breadcrumb = $this->breadcrumbStructuredData(
            (string) data_get($content, 'schema.breadcrumb_label', ''),
            route('download'),
        );
        unset($breadcrumb['@context']);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $application,
                $breadcrumb,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function site(): array
    {
        return $this->pages->pageOrDefault(
            'site',
            config('marketing.pages.site', []),
        );
    }

    /** @return array<string, mixed> */
    private function siteSchema(): array
    {
        $schema = $this->site()['schema'] ?? [];

        return is_array($schema) ? $schema : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function faqStructuredData(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $questions = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item)
                && filled($item['question'] ?? null)
                && filled($item['answer'] ?? null))
            ->map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => trim((string) $item['question']),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => trim((string) $item['answer']),
                ],
            ])
            ->values()
            ->all();

        if ($questions === []) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }
}
