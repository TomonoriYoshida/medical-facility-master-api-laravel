<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Validation messages name a parameter as it is written in the query
 * (`per_page`), not as Laravel would by default ("per page"), so a client
 * sees exactly which parameter to fix.
 */
trait NamesParametersAsIs
{
    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $parameters = array_keys($this->rules());

        return array_combine($parameters, $parameters);
    }
}
