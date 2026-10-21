<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RecordRules;
use Illuminate\Foundation\Http\FormRequest;

class CreateRecordRequest extends FormRequest
{
    use RecordRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => $this->keyRules(),
            'value' => $this->valueRules(),
            'effective_at' => $this->effectiveAtRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->keyMessages('key') + [
            'value.present' => 'A value field is required. Send "value": null to store a JSON null.',
            'effective_at.regex' => 'The effective_at ":input" is not a UNIX timestamp. Use seconds since '
                .'the epoch, UTC, with up to six decimal places, for example 1440568980.123456.',
        ];
    }
}
