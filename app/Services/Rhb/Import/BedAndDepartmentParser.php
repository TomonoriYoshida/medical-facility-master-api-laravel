<?php

namespace App\Services\Rhb\Import;

/**
 * Column I (medical/dental categories only -- entirely absent for
 * pharmacy, verified across real data) interleaves bed-type+count lines
 * (e.g. "療養　　 206") with department-token lines (e.g. "内　消化器内科
 * 循環器内科　呼内") across a record's rows, with no positional marker
 * distinguishing them -- only content shape does: a line containing any
 * digit is a bed-count line, otherwise it's a department-token line.
 *
 * Within a bed-count line, the label and its number are NOT glued into one
 * token -- splitting on the full-width space delimiter yields them as two
 * separate adjacent tokens (e.g. "療養　　 206" -> ["療養", "206"], not
 * ["療養206"], verified against real data), so a digit-only token is
 * paired with the label token immediately preceding it.
 */
final class BedAndDepartmentParser
{
    /**
     * @param  list<array<int, string>>  $rows
     * @return array{bedCounts: array<string, int>, departmentTokens: list<string>}
     */
    public function parse(array $rows): array
    {
        $bedCounts = [];
        $departmentTokens = [];

        foreach ($rows as $row) {
            $value = $row[8] ?? '';

            if (trim($value) === '') {
                continue;
            }

            $tokens = array_values(array_filter(
                array_map('trim', explode("\u{3000}", $value)),
                fn (string $token): bool => $token !== '',
            ));

            if ($tokens === []) {
                continue;
            }

            if (array_any($tokens, fn (string $token): bool => (bool) preg_match('/\d/u', $token))) {
                $pendingLabel = null;

                foreach ($tokens as $token) {
                    if (preg_match('/^\d+$/u', $token)) {
                        if ($pendingLabel !== null) {
                            $bedCounts[$pendingLabel] = (int) $token;
                            $pendingLabel = null;
                        }

                        continue;
                    }

                    if (preg_match('/^(\D+?)(\d+)$/u', $token, $matches)) {
                        $bedCounts[$matches[1]] = (int) $matches[2];
                        $pendingLabel = null;

                        continue;
                    }

                    $pendingLabel = $token;
                }

                continue;
            }

            array_push($departmentTokens, ...$tokens);
        }

        return ['bedCounts' => $bedCounts, 'departmentTokens' => $departmentTokens];
    }
}
