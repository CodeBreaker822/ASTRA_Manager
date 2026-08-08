<?php

namespace App\Jobs\APIController;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiTokenService;
use App\Services\Api\TranscriberPackageService;
use App\Services\Transcription\AppSettingsService;
use Illuminate\Contracts\View\View;

class ApiDashboardController extends Controller
{
    /** Methods an API token can be granted. */
    private const HTTP_METHODS = ['post', 'get', 'put', 'patch', 'delete'];

    /** Providers whose model list can be fetched with the credential itself. */
    private const MODEL_DISCOVERY_PROVIDERS = [
        'gemini',
        'groq_transcription',
        'groq_text_fixer',
        'mistral',
        'mistral_transcription',
        'nvidia',
    ];

    public function index(
        AppSettingsService $settings,
        ApiTokenService $tokens,
        TranscriberPackageService $packages,
    ): View {
        $providers = array_map(
            fn (array $provider): array => [
                ...$provider,
                // Providers that can list their models from the credential itself.
                'supports_discovery' => in_array($provider['provider'], self::MODEL_DISCOVERY_PROVIDERS, true),
            ],
            array_values($settings->providerCards()),
        );

        return view('dashboard.api', [
            'apis' => $tokens->listForManager(),
            'transcriptionProviders' => $providers,
            'providerSections' => $this->providerSections($providers),
            'httpMethods' => self::HTTP_METHODS,
            'transcriberPackage' => $packages->current(),
        ]);
    }

    /**
     * Splits the provider catalog into the two dashboard panels, each already
     * partitioned into configured and still-available providers.
     *
     * @param  array<int, array<string, mixed>>  $providers
     * @return array<int, array{category: string, title: string, description: string, configured: array<int, array<string, mixed>>, available: array<int, array<string, mixed>>}>
     */
    private function providerSections(array $providers): array
    {
        $sections = [
            ['category' => 'transcriber', 'title' => 'Transcribers', 'description' => 'Speech-to-text providers'],
            ['category' => 'text_fixer', 'title' => 'Text Fixers', 'description' => 'Transcript polishing and cleanup providers'],
        ];

        return array_map(function (array $section) use ($providers): array {
            $inCategory = array_filter(
                $providers,
                fn (array $provider): bool => $provider['category'] === $section['category'],
            );

            return [
                ...$section,
                'configured' => array_values(array_filter($inCategory, fn (array $p): bool => (bool) $p['configured'])),
                'available' => array_values(array_filter($inCategory, fn (array $p): bool => ! $p['configured'])),
            ];
        }, $sections);
    }
}
