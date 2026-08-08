<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * Guards against markup that compiles without error but renders as nothing in
 * a browser. Both checks below have caught real regressions.
 */
function bladeViewPaths(): array
{
    return collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->map(fn ($file): string => $file->getPathname())
        ->values()
        ->all();
}

test('no blade directive sits inside a component tag', function () {
    // Blade compiles the directive first, so the component tag then fails to
    // parse and is emitted literally: the browser sees an unknown <x-icon>
    // element and draws nothing. Put the directive on a plain wrapper instead.
    $offenders = [];

    foreach (bladeViewPaths() as $path) {
        $matched = preg_match_all(
            '/<x-[a-z0-9.:-]+[^>]*?@(if|unless|disabled|checked|selected|class|readonly|required|isset|empty)\b[^>]*>/is',
            File::get($path),
            $matches,
        );

        if ($matched) {
            foreach ($matches[0] as $hit) {
                $offenders[] = basename($path).': '.trim(preg_replace('/\s+/', ' ', $hit));
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('every view compiles to valid php and leaves no unparsed component tags', function () {
    $broken = [];

    foreach (bladeViewPaths() as $path) {
        $compiled = Blade::compileString(File::get($path));

        if (preg_match('/<x-[a-z]/i', $compiled)) {
            $broken[] = basename($path).': unparsed component tag';

            continue;
        }

        $temp = tempnam(sys_get_temp_dir(), 'blade').'.php';
        File::put($temp, $compiled);
        exec('php -l '.escapeshellarg($temp).' 2>&1', $output, $status);
        File::delete($temp);

        if ($status !== 0) {
            $broken[] = basename($path).': '.implode(' ', $output);
        }
    }

    expect($broken)->toBe([]);
});
