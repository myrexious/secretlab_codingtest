<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RecordRules;
use Illuminate\Foundation\Http\FormRequest;

class BulkCreateRequest extends FormRequest
{
    use RecordRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pairs' => ['required', 'array', 'min:1', 'max:'.config('kv.bulk_max_pairs')],
            // "distinct" rejects the same key twice in one request. Two records
            // for one key would need two timestamps, and which one is "latest"
            // would come down to row order. Rejecting it is clearer than
            // guessing.
            'pairs.*.key' => [...$this->keyRules(), 'distinct'],
            'pairs.*.value' => $this->valueRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->keyMessages('pairs.*.key') + [
            'pairs.required' => 'A pairs array is required.',
            'pairs.array' => 'The pairs field must be an array of {"key": ..., "value": ...} objects.',
            'pairs.min' => 'A bulk request must contain at least one pair.',
            'pairs.max' => 'A bulk request may contain at most '.config('kv.bulk_max_pairs').' pairs.',
            'pairs.*.key.distinct' => 'The key ":input" appears more than once in this request. '
                .'One request may not write the same key twice.',
            'pairs.*.value.present' => 'Every pair needs a value field. Send "value": null to store a JSON null.',
        ];
    }
}
