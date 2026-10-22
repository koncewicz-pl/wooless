<?php

namespace App\Services;

/**
 * Turns the OCTANE_HOST value (one domain or a comma-separated list) into the
 * values the FrankenPHP / Caddy process needs, so the public domains live in
 * .env instead of in the supervisor command line.
 */
class OctaneHosts
{
    /**
     * @return list<string>
     */
    public static function parse(?string $hosts): array
    {
        $parsed = array_values(array_filter(array_map('trim', explode(',', (string) $hosts))));

        return $parsed === [] ? ['localhost'] : $parsed;
    }

    public static function primary(?string $hosts): string
    {
        return self::parse($hosts)[0];
    }

    /**
     * The Caddy site address: every domain on HTTPS with the given port, so
     * Caddy serves and obtains certificates for all of them.
     */
    public static function caddySiteAddress(?string $hosts, int $port): string
    {
        return implode(', ', array_map(
            fn (string $host): string => "https://{$host}:{$port}",
            self::parse($hosts),
        ));
    }
}
