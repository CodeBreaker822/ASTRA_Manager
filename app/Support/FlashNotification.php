<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\ViewErrorBag;

/**
 * Reduces the several ways the app flashes a message down to the single toast
 * the layout shows.
 */
class FlashNotification
{
    /** Flash keys that carry a plain message, in the order they win. */
    private const TYPES = ['success', 'error', 'warning', 'info'];

    /**
     * @return array{message: string, type: string}|null
     */
    public static function current(?ViewErrorBag $errors = null): ?array
    {
        $toast = Session::get('toast');

        if (is_array($toast)) {
            return [
                'message' => (string) ($toast['message'] ?? ''),
                'type' => (string) ($toast['type'] ?? 'success'),
            ];
        }

        foreach (self::TYPES as $type) {
            if (filled(Session::get($type))) {
                return ['message' => (string) Session::get($type), 'type' => $type];
            }
        }

        $firstError = $errors?->first();

        return filled($firstError)
            ? ['message' => (string) $firstError, 'type' => 'error']
            : null;
    }
}
