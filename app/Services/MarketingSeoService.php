<?php

namespace App\Services;

class MarketingSeoService
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $structuredData
     * @return array{
     *     title: string,
     *     description: string,
     *     canonical_url: string,
     *     image_url: string,
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

        return [
            'title' => trim((string) ($seo['title'] ?? config('app.name', 'JERVA Transcriber'))),
            'description' => trim((string) ($seo['description'] ?? '')),
            'canonical_url' => $canonicalUrl,
            'image_url' => asset('JervaLogo.png'),
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
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => route('home').'#organization',
                    'name' => 'JERVA Transcriber',
                    'url' => route('home'),
                    'logo' => asset('JervaLogo.png'),
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => route('home').'#website',
                    'name' => 'JERVA Transcriber',
                    'url' => route('home'),
                    'publisher' => ['@id' => route('home').'#organization'],
                ],
                [
                    '@type' => 'WebApplication',
                    '@id' => route('home').'#web-application',
                    'name' => 'JERVA Transcriber',
                    'url' => route('home'),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web browser, Windows',
                    'description' => 'Online and offline audio transcription for meetings, interviews, lectures, podcasts, and voice recordings.',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => 0,
                        'priceCurrency' => $pricing['currency'],
                        'description' => $pricing['free_minutes_per_day']
                            ? "{$pricing['free_minutes_per_day']} online transcription minutes free each day"
                            : 'Free daily online transcription allowance',
                    ],
                    'featureList' => [
                        'Online and offline transcription',
                        'Multilingual Whisper transcription',
                        'Voice activity detection',
                        'Speaker separation',
                        'Transcript cleanup and summaries',
                        'TXT, Word, and Excel exports',
                    ],
                    'publisher' => ['@id' => route('home').'#organization'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function breadcrumbStructuredData(string $name, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
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
     * @return array<string, mixed>
     */
    public function downloadStructuredData(array $release): array
    {
        $application = [
            '@type' => 'SoftwareApplication',
            '@id' => route('download').'#windows-application',
            'name' => 'JERVA Transcriber for Windows',
            'url' => route('download'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Windows',
            'description' => 'Free offline Whisper transcription for Windows with voice activity detection, speaker separation, transcript cleanup, summaries, and exports.',
            'offers' => [
                '@type' => 'Offer',
                'price' => 0,
                'priceCurrency' => 'USD',
                'availability' => $release['available']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
            'featureList' => [
                'Offline Whisper transcription',
                'Live recording and audio uploads',
                'Silero voice activity detection',
                'Speaker separation',
                'TXT, Word, and Excel exports',
            ],
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

        $breadcrumb = $this->breadcrumbStructuredData('Download', route('download'));
        unset($breadcrumb['@context']);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $application,
                $breadcrumb,
            ],
        ];
    }
}
