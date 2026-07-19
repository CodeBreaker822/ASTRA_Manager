<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DownloadController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/Download', [
            'release' => $this->latestRelease(),
        ]);
    }

    public function latest(): RedirectResponse
    {
        $release = $this->latestRelease();

        abort_unless($release['available'], 404, 'No JERVA desktop release has been uploaded yet.');

        return redirect()->route('transcriber.update.download', [
            'zipfile' => $release['zipfile'],
        ]);
    }

    /**
     * @return array{available: bool, platform: string, version: mixed, zipfile: string|null, size: string|null, published_at: string|null, download_url: string|null}
     */
    private function latestRelease(): array
    {
        $directory = Storage::disk('local')->path('transcriber');
        $versionPath = $directory.DIRECTORY_SEPARATOR.'version.json';
        $version = null;

        if (File::isReadable($versionPath)) {
            try {
                $payload = json_decode(File::get($versionPath), true, 512, JSON_THROW_ON_ERROR);
                $version = is_array($payload) ? ($payload['version'] ?? null) : null;
            } catch (\Throwable) {
                $version = null;
            }
        }

        $zipFiles = File::isDirectory($directory)
            ? array_values(array_filter(
                File::files($directory),
                fn ($file): bool => strtolower($file->getExtension()) === 'zip',
            ))
            : [];

        usort($zipFiles, fn ($left, $right): int => strnatcasecmp($left->getFilename(), $right->getFilename()));

        $zip = $zipFiles[0] ?? null;

        return [
            'available' => $zip !== null,
            'platform' => 'Windows',
            'version' => $version,
            'zipfile' => $zip?->getFilename(),
            'size' => $zip ? $this->humanFileSize($zip->getSize()) : null,
            'published_at' => $zip ? date('F j, Y', $zip->getMTime()) : null,
            'download_url' => $zip ? route('download.latest', ['platform' => 'windows']) : null,
        ];
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
