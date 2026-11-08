<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Reaction formula type ids to look up in the user's blueprints. Same
 * rules as the old formula-stock route.
 */
class FormulaStockRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'formula_type_ids' => 'required|array|min:1',
            'formula_type_ids.*' => 'integer',
        ];
    }
}
