<?php

namespace App\Http\Controllers;

use App\Services\MarketingSeoService;
use App\Services\PageContentService;
use App\Services\Billing\PlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function index(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault('download', config('marketing.pages.download', []));
        $release = $this->latestRelease();

        return view('marketing.download', [
            'release' => $release,
            'content' => $content,
            'card' => $content['download_card'],
            'pricing' => $plans->marketingSummary(),
            'seo' => $seo->metadata(
                $content,
                route('download'),
                $seo->downloadStructuredData($release, $content),
            ),
        ]);
    }

    public function latest(): BinaryFileResponse
    {
        $package = $this->latestPackage();

        abort_unless($package !== null, 404, 'No JERVA desktop release has been uploaded yet.');

        return response()->download($package['path'], $package['filename'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * @return array{available: bool, platform: string, size: string|null, published_at: string|null, published_at_iso: string|null, download_url: string|null}
     */
    private function latestRelease(): array
    {
        $package = $this->latestPackage();

        return [
            'available' => $package !== null,
            'platform' => 'Windows',
            'size' => $package ? $this->humanFileSize($package['size']) : null,
            'published_at' => $package ? date('F j, Y', $package['modified_at']) : null,
            'published_at_iso' => $package ? date(DATE_ATOM, $package['modified_at']) : null,
            'download_url' => $package ? route('download.latest') : null,
        ];
    }

    /**
     * @return array{path: string, filename: string, size: int, modified_at: int}|null
     */
    private function latestPackage(): ?array
    {
        $directory = Storage::disk('local')->path('transcriber');

        $zipFiles = File::isDirectory($directory)
            ? array_values(array_filter(
                File::files($directory),
                fn ($file): bool => strtolower($file->getExtension()) === 'zip',
            ))
            : [];

        usort($zipFiles, fn ($left, $right): int => strnatcasecmp($left->getFilename(), $right->getFilename()));

        foreach ($zipFiles as $zip) {
            if (! $this->ensureReadable($zip)) {
                continue;
            }

            return [
                'path' => $zip->getPathname(),
                'filename' => $zip->getFilename(),
                'size' => $zip->getSize(),
                'modified_at' => $zip->getMTime(),
            ];
        }

        return null;
    }

    private function ensureReadable(SplFileInfo $file): bool
    {
        $path = $file->getPathname();

        if (File::isReadable($path)) {
            return true;
        }

        File::chmod($path, 0644);
        clearstatcache(true, $path);

        if (File::isReadable($path)) {
            return true;
        }

        Log::warning('Transcriber App Package exists but is not readable.', [
            'path' => $path,
            'permissions' => substr(sprintf('%o', File::chmod($path)), -4),
        ]);

        return false;
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = max(0, $bytes);

        for ($unit = 0; $size >= 1024 && $unit < count($units) - 1; $unit++) {
            $size /= 1024;
        }

        return number_format($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
