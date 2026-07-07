<?php

namespace App\Traits;

use App\Services\AuditLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;

trait Gates
{
    /**
     * Log unauthorized access attempt with DDOS protection and VPN detection
     */
    public static function logUnauthorizedAccess(string $gateName, string $url): void
    {
        $ipAddress = request()->ip();
        $sessionId = session()->getId();
        $userAgent = request()->userAgent();

        // Enhanced security checks

        // 1. Check for VPN/Proxy/Hosting IP ranges
        if (self::isVpnOrProxy($ipAddress)) {
            // Stricter limits for VPN/proxy traffic
            $vpnKey = "unauth_vpn_{$ipAddress}";
            $vpnCount = Cache::get($vpnKey, 0);

            if ($vpnCount >= 3) { // Much lower limit for VPN traffic
                return; // Silent block for suspicious VPN traffic
            }

            Cache::put($vpnKey, $vpnCount + 1, now()->addHours(6)); // Longer block time
        }

        // 2. IP-based rate limiting (stricter for VPN, normal for direct)
        $isVpn = self::isVpnOrProxy($ipAddress);
        $ipLimit = $isVpn ? 5 : 10; // Lower limit for VPN traffic
        $ipBlockTime = $isVpn ? now()->addHours(6) : now()->addHour();

        $ipKey = "unauth_ip_{$ipAddress}";
        $ipCount = Cache::get($ipKey, 0);

        if ($ipCount >= $ipLimit) {
            return; // Silently block
        }

        // 3. Session-based deduplication (stricter for VPN)
        $sessionKey = "unauthorized_access_{$gateName}_".md5($url);

        if (Session::has($sessionKey)) {
            return; // Already logged in this session
        }

        // 4. Global rate limiting with VPN awareness
        $globalKey = 'unauth_global_'.date('Y-m-d-H-i');
        $globalCount = Cache::get($globalKey, 0);

        if ($globalCount >= 100) {
            return; // System under attack
        }

        // 5. Enhanced bot detection + VPN check
        if (self::isKnownBot($userAgent) || ($isVpn && self::isSuspiciousCombination($userAgent, $ipAddress))) {
            return; // Don't log suspicious traffic
        }

        // Proceed with logging (with enhanced metadata)
        try {
            $auditService = app(AuditLogService::class);

            $auditService->logSecurity('unauthorized_access_attempt', 'User attempted to access restricted route', [
                'gate_name' => $gateName,
                'attempted_url' => $url,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'session_id' => $sessionId,
                'location_info' => [
                    'method' => request()->method(),
                    'referer' => request()->header('referer'),
                ],
                'security_flags' => [
                    'rate_limited' => false,
                    'ip_count' => $ipCount + 1,
                    'global_count' => $globalCount + 1,
                    'vpn_detected' => $isVpn,
                    'ip_type' => self::getIpType($ipAddress),
                ],
            ]);

            // Update counters with appropriate expiration
            Cache::put($ipKey, $ipCount + 1, $ipBlockTime);
            Cache::put($globalKey, $globalCount + 1, now()->addMinute());

            // Mark as logged for this session
            Session::put($sessionKey, true);

        } catch (\Exception $e) {
            // Silent fail - don't let logging errors break the application
            return;
        }
    }

    /**
     * Check if IP is from VPN/Proxy/Hosting range
     * DISABLED: Currently disabled - returns false immediately
     */
    private static function isVpnOrProxy(string $ip): bool
    {
        // Common VPN/proxy/hosting IP ranges (simplified version)
        $vpnRanges = [
            // Cloud hosting providers
            '3.0.0.0/8',     // AWS, Google Cloud, etc.
            '52.0.0.0/8',    // AWS
            '104.0.0.0/8',   // Various cloud providers
            '107.0.0.0/8',   // Cloud providers
            '172.0.0.0/8',   // Various hosting
            '185.0.0.0/8',   // European hosting
            '208.0.0.0/8',   // US hosting

            // Known VPN providers (simplified)
            '5.0.0.0/8',     // Some VPN ranges
            '25.0.0.0/8',    // Various VPN/proxy
            '37.0.0.0/8',    // European VPN
            '46.0.0.0/8',    // Various proxy services
            '78.0.0.0/8',    // Some VPN ranges
            '85.0.0.0/8',    // European VPN/proxy
            '94.0.0.0/8',    // Some VPN services
            '185.0.0.0/8',   // Various VPN
            '213.0.0.0/8',   // European VPN/proxy
        ];

        // Check if IP matches any VPN/proxy range
        foreach ($vpnRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        // Additional checks for common proxy ports/headers
        $proxyHeaders = [
            'X-Forwarded-For',
            'X-Real-IP',
            'X-Forwarded',
            'X-Cluster-Client-IP',
            'CF-Connecting-IP', // Cloudflare
            'True-Client-IP',
        ];

        foreach ($proxyHeaders as $header) {
            if (request()->header($header) && request()->header($header) !== $ip) {
                return true; // Proxy detected
            }
        }

        return false;
    }

    /**
     * Check if IP is in specified range
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$subnet, $mask] = explode('/', $range);

            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
        }

        return $ip === $range;
    }

    /**
     * Get IP type classification
     */
    private static function getIpType(string $ip): string
    {
        if (self::isVpnOrProxy($ip)) {
            return 'vpn_proxy';
        }

        // Check for private/internal IPs
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            return 'private';
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            return 'public';
        }

        return 'unknown';
    }

    /**
     * Check for suspicious user agent + IP combination
     */
    private static function isSuspiciousCombination(string $userAgent, string $ip): bool
    {
        // Suspicious: VPN IP with datacenter-like user agent
        $datacenterPatterns = [
            'curl', 'wget', 'python', 'java', 'node', 'go-http',
            'bot', 'crawler', 'spider', 'scraper',
        ];

        $userAgentLower = strtolower($userAgent);

        foreach ($datacenterPatterns as $pattern) {
            if (str_contains($userAgentLower, $pattern)) {
                return true; // Suspicious combination
            }
        }

        return false;
    }

    /**
     * Check if user agent is a known bot/crawler
     */
    private static function isKnownBot(string $userAgent): bool
    {
        $bots = [
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'facebookexternalhit', 'twitterbot', 'rogerbot',
            'linkedinbot', 'embedly', 'quora link preview', 'pinterestbot',
            'slackbot', 'telegrambot', 'applebot', 'whatsapp', 'crawler',
        ];

        $userAgentLower = strtolower($userAgent);

        foreach ($bots as $bot) {
            if (str_contains($userAgentLower, $bot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all available gates defined in the application
     */
    private function getAllGates()
    {
        // Get all gates dynamically from the Gate facade
        $gates = Gate::abilities();
        $categorizedGates = [];
        $actions = ['view', 'add', 'edit', 'delete', 'manage', 'create', 'update'];
        $seen = [];

        // Categorize gates automatically based on their names
        foreach ($gates as $gateName => $callback) {
            $category = '';
            $label = '';

            $parts = explode('-', $gateName, 2);
            if (count($parts) === 1 && str_contains($gateName, '.')) {
                $parts = explode('.', $gateName, 2);
            }

            if (count($parts) > 1) {
                $first = $parts[0];
                $second = $parts[1];

                $firstLower = strtolower($first);
                $secondLower = strtolower($second);

                if (in_array($firstLower, $actions, true)) {
                    $actionRaw = $first;
                    $categoryRaw = $second;
                } elseif (in_array($secondLower, $actions, true)) {
                    $actionRaw = $second;
                    $categoryRaw = $first;
                } else {
                    $actionRaw = $second;
                    $categoryRaw = $first;
                }

                if (str_ends_with($categoryRaw, '_manager')) {
                    $categoryRaw = substr($categoryRaw, 0, -strlen('_manager'));
                }

                $category = strtoupper(trim(str_replace(['_', '.'], ' ', $categoryRaw)));
                $actionIsSimple = in_array(strtolower($actionRaw), $actions, true);

                if ($actionIsSimple) {
                    $label = strtoupper($actionRaw).' '.$category;
                } else {
                    $label = strtoupper(trim(str_replace(['_', '.'], ' ', $actionRaw)));
                }
            } else {
                $category = strtoupper(trim(str_replace(['_', '.'], ' ', $gateName)));
                $label = $category;
            }

            if (! isset($categorizedGates[$category])) {
                $categorizedGates[$category] = [];
            }

            if (isset($seen[$category][$label])) {
                continue;
            }

            $seen[$category][$label] = true;

            $categorizedGates[$category][] = ['name' => $gateName, 'label' => $label];
        }

        // Sort categories alphabetically
        ksort($categorizedGates);

        return $categorizedGates;
    }
}
