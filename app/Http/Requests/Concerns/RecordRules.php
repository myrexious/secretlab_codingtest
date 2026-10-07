<?php

namespace App\Http\Requests\Concerns;

use App\Support\RawJson;
use Closure;

/**
 * Validation shared by the single and bulk create requests.
 *
 * Error text names the field, the rule and the offending value. A caller should
 * never have to guess which of fifty pairs was rejected.
 */
trait RecordRules
{
    /** @return list<mixed> */
    protected function keyRules(): array
    {
        return [
            'required',
            'string',
            'max:'.config('kv.key_max_length'),
            'regex:/^'.config('kv.key_pattern').'$/',
        ];
    }

    /** @return list<mixed> */
    protected function valueRules(): array
    {
        // "present", not "required": null is a storable value, and "required"
        // would reject it.
        return ['present', $this->valueSizeRule()];
    }

    /**
     * Reject a malformed body before field validation runs.
     *
     * Laravel decodes an unparseable JSON body to an empty array, so without
     * this the caller is told "A key is required" when the real problem is that
     * their body is not JSON at all.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isJson() && ! json_validate((string) $this->getContent())) {
            abort(400, 'The request body is not valid JSON.');
        }
    }

    protected function valueSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $max = (int) config('kv.value_max_bytes');

            // No try/catch: the value came out of a successful json_decode of
            // the request body, so it is encodable by construction.
            $size = strlen(RawJson::encodeForStorage($value));

            if ($size > $max) {
                $fail(sprintf(
                    'The %s field is %s bytes once encoded as JSON, over the %s byte limit.',
                    $attribute,
                    number_format($size),
                    number_format($max),
                ));
            }
        };
    }

    /** @return array<string, string> */
    protected function keyMessages(string $field): array
    {
        return [
            "{$field}.required" => 'A key is required.',
            "{$field}.string" => 'The key must be a string. Received :input.',
            "{$field}.max" => 'The key may not be longer than '.config('kv.key_max_length').' characters.',
            "{$field}.regex" => 'The key ":input" is not allowed. A key may contain only letters, '
                .'digits, and the characters . _ : and -. In particular it may not contain "/", '
                .'because the read route would not be able to address it.',
        ];
    }
}
