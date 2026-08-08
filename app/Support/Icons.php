<?php

namespace App\Support;

/**
 * Resolves an icon name to its SVG path markup. Names may be a slug
 * ("file-text") or the PascalCase alias the CMS stores ("FileText").
 */
class Icons
{
    /**
     * Returns the inner SVG markup, or null when neither the name nor the
     * fallback is a known icon.
     */
    public static function paths(string $name, ?string $fallback = null): ?string
    {
        $paths = self::lookup($name);

        if ($paths !== null) {
            return $paths;
        }

        return $fallback !== null ? self::lookup($fallback) : null;
    }

    private static function lookup(string $name): ?string
    {
        $slug = config('icons.aliases.'.$name, $name);

        $paths = config('icons.paths.'.$slug);

        return is_string($paths) ? $paths : null;
    }
}
