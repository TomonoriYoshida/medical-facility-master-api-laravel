<?php

namespace App\Services\AccessLog;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves the country of an access-log IP so a daily alert can name where
 * suspicious traffic comes from. Only the handful of flagged IPs in one
 * check are looked up, so a free, keyless endpoint (ip-api.com by default)
 * is enough; the result is cached for the run since the same IP appears in
 * more than one finding.
 *
 * A private, reserved or documentation address, an unconfigured endpoint, or
 * any network error yields null, and the IP is then shown without a country.
 * Geolocation is only a hint for the reader (a VPN or proxy shows its exit
 * country, not the sender's), never a reason to block, so a failure is never
 * fatal to the check.
 */
class IpCountryLocator
{
    /**
     * Reserved-for-documentation ranges (RFC 5737, RFC 3849) that are public
     * to filter_var but can never be geolocated, so they are skipped without
     * a lookup. This also keeps the test suite's example IPs offline.
     */
    private const array DOCUMENTATION_PREFIXES = ['192.0.2.', '198.51.100.', '203.0.113.', '2001:db8:'];

    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * A human label for the IP's country ("日本 (JP)"), or null when it
     * cannot be determined.
     */
    public function label(string $ip): ?string
    {
        if (array_key_exists($ip, $this->cache)) {
            return $this->cache[$ip];
        }

        return $this->cache[$ip] = $this->resolve($ip);
    }

    private function resolve(string $ip): ?string
    {
        $endpoint = config()->string('api.access_log.geo.endpoint');

        if ($endpoint === '' || ! $this->isPublic($ip)) {
            return null;
        }

        try {
            $response = Http::timeout(config()->integer('api.access_log.geo.timeout'))
                ->get(str_replace('{ip}', $ip, $endpoint));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->format($response->json());
    }

    /**
     * Reads the country from the response, tolerating the field names used by
     * common keyless providers (ip-api.com: country / countryCode; ipwho.is,
     * ipapi.co: country / country_code).
     */
    private function format(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $country = $body['country'] ?? null;
        $code = $body['countryCode'] ?? $body['country_code'] ?? null;

        if (! is_string($country) || $country === '') {
            return null;
        }

        return is_string($code) && $code !== '' ? "{$country} ({$code})" : $country;
    }

    private function isPublic(string $ip): bool
    {
        foreach (self::DOCUMENTATION_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
