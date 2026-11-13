<?php

namespace App\Mcp\Support;

/**
 * The API's attribution (meta.attribution), shortened for the tools'
 * results: the notice, the license, the sources of the data the tool
 * returns and where every source is listed. The sources' terms ask for
 * it wherever the data is shown, so the server's instructions ask the AI
 * to pass it on.
 */
final class Attribution
{
    /**
     * @param  array<string, mixed>  $body  An API response
     * @param  list<string>  $sources  The meta.attribution keys of the sources used (e.g. station_source)
     * @return array{notice: string, sources: list<string>, license: string, details: string}
     */
    public static function from(array $body, array $sources = []): array
    {
        $attribution = $body['meta']['attribution'] ?? [];

        return [
            'notice' => (string) ($attribution['notice'] ?? ''),
            'sources' => array_values(array_filter(array_map(
                fn (string $key): string => (string) ($attribution[$key]['name'] ?? ''),
                $sources,
            ))),
            'license' => (string) ($attribution['license']['name'] ?? ''),
            'details' => route('scramble.docs.ui'),
        ];
    }
}
