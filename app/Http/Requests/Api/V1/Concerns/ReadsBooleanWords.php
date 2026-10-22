<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Boolean query parameters also take the words `true` / `false`: the OpenAPI
 * document types them as booleans, so that is what generated clients send,
 * but Laravel's boolean rule only takes 1 / 0 from a query string. The words
 * become booleans before validation; 1 / 0 still work as before.
 */
trait ReadsBooleanWords
{
    /**
     * @return list<string>
     */
    abstract protected function booleanParameters(): array;

    protected function prepareForValidation(): void
    {
        $booleans = [];

        foreach ($this->booleanParameters() as $parameter) {
            $value = $this->query($parameter);

            if ($value === 'true' || $value === 'false') {
                $booleans[$parameter] = $value === 'true';
            }
        }

        $this->merge($booleans);
    }
}
