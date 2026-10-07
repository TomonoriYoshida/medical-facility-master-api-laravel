<?php

namespace App\Services\OpenApi;

use BackedEnum;
use Dedoc\Scramble\Support\Generator\OpenApi;

/**
 * A Scramble document transformer that lists each enum schema's codes with
 * their Japanese names (the enum's label()), after the description its
 * docblock gives. The names come from label() itself, so the docs cannot
 * drift from what the API returns as `label`. An enum without label()
 * keeps the table Scramble makes from its cases' docblocks.
 *
 * Either table is set off by a blank line: Scramble puts its own right
 * under the description, where Markdown reads it as part of the paragraph.
 *
 * Scramble names a schema after the class's short name, so the enum is
 * looked up in App\Enums.
 */
class DescribesEnumCases
{
    public function __invoke(OpenApi $openApi): void
    {
        foreach ($openApi->components->schemas as $name => $schema) {
            $className = 'App\\Enums\\'.$name;

            if (! is_a($className, BackedEnum::class, true)) {
                continue;
            }

            $labels = $this->labels($className::cases());

            $table = $labels === null
                ? (string) $schema->type->getAttribute('casesDescription', '')
                : $this->labelTable($labels);

            $schema->type->setDescription(trim(
                $schema->type->getAttribute('description', '')."\n\n".$table,
            ));
        }
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<int|string, string>|null Each case's label() by its value, or null without label().
     */
    private function labels(array $cases): ?array
    {
        $labels = [];

        foreach ($cases as $case) {
            if (! method_exists($case, 'label')) {
                return null;
            }

            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * @param  array<int|string, string>  $labels
     */
    private function labelTable(array $labels): string
    {
        return implode("\n", [
            '| コード | 名前 |',
            '|---|---|',
            ...array_map(
                fn (int|string $value, string $label): string => "| `{$value}` | {$label} |",
                array_keys($labels),
                $labels,
            ),
        ]);
    }
}
