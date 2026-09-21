<?php

namespace App\Services\Mhlw;

use Carbon\CarbonImmutable;

final class MhlwDatasetLinkResolver
{
    /**
     * Resolve the most recently published download link for a dataset slug.
     *
     * The MHLW index page keeps every historical dated snapshot of a dataset
     * live at once (e.g. 2025年6月, 2025年12月, 2026年6月 snapshots all
     * resolve simultaneously), and the current snapshot's extension is
     * inconsistently ".zip" vs ".csv.zip". So every matching link is
     * collected and the one with the largest YYYYMMDD date wins, rather
     * than trusting link order or the extension.
     *
     * The page itself may be Shift_JIS encoded, but only the ASCII href
     * attribute is matched here (never the Japanese link text), so no
     * encoding conversion is needed.
     */
    public function resolveLatest(string $html, string $slug, string $baseUrl): ?ResolvedMhlwDatasetLink
    {
        $pattern = '#href="(/content/11121000/'.preg_quote($slug, '#').'_(\d{8})\.(?:csv\.)?zip)"#';

        if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return null;
        }

        usort($matches, fn (array $a, array $b): int => $b[2] <=> $a[2]);

        $best = $matches[0];

        return new ResolvedMhlwDatasetLink(
            url: $baseUrl.$best[1],
            filename: basename((string) $best[1]),
            publishedOn: CarbonImmutable::createFromFormat('Ymd', $best[2]),
        );
    }
}
