<?php

namespace App\Services\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;

/**
 * A Scramble document transformer for the docs' Japanese text:
 *
 * - The English descriptions Scramble writes itself (pagination fields,
 *   error responses, response summaries) are put into Japanese.
 * - A line break that only wraps a docblock in the middle of Japanese text
 *   is removed. Markdown renders it as a space, which Japanese does not
 *   put between words. Breaks before a list item, a table row or a blank
 *   line are kept.
 * - A paragraph repeated in one description is given once. Scramble
 *   repeats a response's summary for each of its alternative shapes (the
 *   list's page-number and cursor pagination).
 *
 * It walks every public property of the generated document, since those
 * descriptions sit on schemas, parameters and responses alike.
 */
class LocalizesDescriptions
{
    /**
     * @var array<string, string>
     */
    private const array TRANSLATIONS = [
        'Base path for paginator generated URLs.' => 'ページのURLの基になるパス',
        'Generated paginator links.' => 'ページ番号の一覧（前後のページへのリンクを含む）',
        'Number of items shown per page.' => '1ページあたりの件数',
        'Number of the last item in the slice.' => 'このページの最後の項目が全体で何件目か',
        'The "cursor" that points to the next set of items.' => '次のページのカーソル（最後のページならnull）',
        'The "cursor" that points to the previous set of items.' => '前のページのカーソル（最初のページならnull）',
        'Total number of items being paginated.' => '条件に合う件数',
        'A detailed description of each field that failed validation.' => 'パラメータごとのエラーメッセージ',
        'Errors overview.' => 'エラーの概要',
        'Error overview.' => 'エラーの概要',
        'Validation error' => 'パラメータの誤り',
        'Not found' => '見つからない',
    ];

    /**
     * Response summaries naming the resource, e.g. "Paginated set of `X`".
     *
     * @var array<string, string>
     */
    private const array PATTERNS = [
        '/^Paginated set of `(\w+)`$/m' => '`$1` のページ',
        '/^Array of `(\w+)`$/m' => '`$1` の配列',
    ];

    /**
     * A break after Japanese text (kana, kanji or full-width punctuation)
     * that is not followed by a blank line or a Markdown block.
     */
    private const string WRAPPED_LINE = '/(?<=[\p{Han}\p{Hiragana}\p{Katakana}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}])\n(?![\s\-*|#]|\d+\.)/u';

    public function __invoke(OpenApi $openApi): void
    {
        $visited = [];

        $this->translate($openApi, $visited);
    }

    /**
     * @param  array<int, true>  $visited  Object ids seen so far: references point back at the components, so the graph has cycles.
     */
    private function translate(mixed $node, array &$visited): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->translate($child, $visited);
            }

            return;
        }

        if (! is_object($node) || isset($visited[spl_object_id($node)])) {
            return;
        }

        $visited[spl_object_id($node)] = true;

        if (isset($node->description) && is_string($node->description)) {
            $description = preg_replace(
                [...array_keys(self::PATTERNS), self::WRAPPED_LINE],
                [...array_values(self::PATTERNS), ''],
                self::TRANSLATIONS[$node->description] ?? $node->description,
            ) ?? $node->description;

            $node->description = implode("\n\n", array_unique(explode("\n\n", $description)));
        }

        foreach (get_object_vars($node) as $child) {
            $this->translate($child, $visited);
        }
    }
}
