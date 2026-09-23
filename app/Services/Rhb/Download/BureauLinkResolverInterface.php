<?php

namespace App\Services\Rhb\Download;

/**
 * Each of the 8 regional health bureaus publishes its download links on a
 * differently-structured page (no shared index or URL pattern the way the
 * old MHLW pipeline had), so link resolution gets one implementation per
 * bureau rather than a single shared regex-based resolver.
 */
interface BureauLinkResolverInterface
{
    /**
     * @return list<ResolvedRhbDatasetLink>
     */
    public function resolve(string $html, string $baseUrl): array;
}
