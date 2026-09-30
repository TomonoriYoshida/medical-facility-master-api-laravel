<?php

namespace App\Services\AccessLog;

use Carbon\CarbonInterface;

/**
 * Counts one period of the web server's access log: Caddy's JSON lines in
 * the current file plus the rotated ones (access-<time>-<reason>.log.gz,
 * gzipped), which are all read the same way because gzopen() also reads
 * uncompressed files. A rotated file last written before the period can't
 * contain any of it, so it is skipped without being read.
 */
class AccessLogSummary
{
    public int $filesRead = 0;

    public int $total = 0;

    /** @var array<int, int> */
    private array $countsByStatus = [];

    /** @var array<string, int> */
    private array $requestsByIp = [];

    /** @var array<string, int> */
    private array $rateLimitedByIp = [];

    /** @var array<string, int> */
    private array $notFoundByPath = [];

    private function __construct(
        private readonly float $from,
        private readonly float $to,
    ) {}

    public static function read(string $path, CarbonInterface $from, CarbonInterface $to): self
    {
        $summary = new self($from->getTimestamp(), $to->getTimestamp());
        $name = pathinfo($path, PATHINFO_FILENAME);

        foreach (glob(dirname($path)."/{$name}*.log*") ?: [] as $file) {
            if (filemtime($file) < $from->getTimestamp()) {
                continue;
            }

            $handle = gzopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            while (($line = gzgets($handle)) !== false) {
                $summary->add($line);
            }

            gzclose($handle);
            $summary->filesRead++;
        }

        return $summary;
    }

    public function count(int $status): int
    {
        return $this->countsByStatus[$status] ?? 0;
    }

    public function countServerErrors(): int
    {
        return array_sum(array_filter(
            $this->countsByStatus,
            fn (int $status): bool => $status >= 500,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @return array<string, array{requests: int, rate_limited: int}>
     */
    public function topIps(int $limit): array
    {
        $top = $this->requestsByIp;
        arsort($top);

        $result = [];

        foreach (array_slice($top, 0, $limit, preserve_keys: true) as $ip => $requests) {
            $result[(string) $ip] = ['requests' => $requests, 'rate_limited' => $this->rateLimitedByIp[$ip] ?? 0];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function ipsSendingAtLeast(int $requests): array
    {
        $ips = array_filter($this->requestsByIp, fn (int $count): bool => $count >= $requests);
        arsort($ips);

        return $ips;
    }

    /**
     * @return array<string, int>
     */
    public function topNotFoundPaths(int $limit): array
    {
        $top = $this->notFoundByPath;
        arsort($top);

        return array_slice($top, 0, $limit, preserve_keys: true);
    }

    private function add(string $line): void
    {
        $entry = json_decode($line, true);

        if (! is_array($entry) || ! is_numeric($entry['ts'] ?? null) || ! is_int($entry['status'] ?? null)) {
            return;
        }

        $timestamp = (float) $entry['ts'];

        if ($timestamp < $this->from || $timestamp >= $this->to) {
            return;
        }

        $status = $entry['status'];
        $request = is_array($entry['request'] ?? null) ? $entry['request'] : [];
        $ip = $request['client_ip'] ?? $request['remote_ip'] ?? null;
        $ip = is_string($ip) ? $ip : '-';

        $this->total++;
        $this->countsByStatus[$status] = ($this->countsByStatus[$status] ?? 0) + 1;
        $this->requestsByIp[$ip] = ($this->requestsByIp[$ip] ?? 0) + 1;

        if ($status === 429) {
            $this->rateLimitedByIp[$ip] = ($this->rateLimitedByIp[$ip] ?? 0) + 1;
        }

        if ($status === 404) {
            $uri = is_string($request['uri'] ?? null) ? $request['uri'] : '-';
            $path = strtok($uri, '?') ?: $uri;
            $this->notFoundByPath[$path] = ($this->notFoundByPath[$path] ?? 0) + 1;
        }
    }
}
