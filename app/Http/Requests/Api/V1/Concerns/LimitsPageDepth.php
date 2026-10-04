<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Page numbers reach only the first api.max_paginated_rows rows. Offset
 * pagination reads and discards every row before the page, so deep pages
 * cost the most on the server (page 2,000 of 100 rows: ~0.2s) while being
 * the least useful to people; walking the whole list is what the bulk
 * download and cursor pagination are for.
 */
trait LimitsPageDepth
{
    private const int DEFAULT_PER_PAGE = 25;

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * The last page number allowed at the requested page size. The first
     * page is always allowed, even when one page holds more than the limit.
     */
    public function maxPage(): int
    {
        $perPage = filter_var($this->input('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        return max(1, intdiv(config()->integer('api.max_paginated_rows'), $perPage ?: self::DEFAULT_PER_PAGE));
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function pageRules(): array
    {
        return [
            /** ページ番号。最初の1万件まで（上限は `meta.max_page`）。それより先は、条件を絞り込んでください */
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.$this->maxPage()],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function pageMessages(string $beyondLimit): array
    {
        return [
            'page.max' => 'ページ番号は :max までです（最初の'.number_format(config()->integer('api.max_paginated_rows')).'件まで）。'.$beyondLimit,
        ];
    }
}
