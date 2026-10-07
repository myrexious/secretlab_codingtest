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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->keyMessages('key') + [
            'value.present' => 'A value field is required. Send "value": null to store a JSON null.',
        ];
    }
}
