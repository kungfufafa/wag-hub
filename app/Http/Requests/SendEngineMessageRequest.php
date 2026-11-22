<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendEngineMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            $this->merge(['type' => 'text']);
        }

        if (is_string($this->input('to'))) {
            // "0812-3456-7890" and "+62 812 3456 7890" both become "6281234567890".
            $this->merge(['to' => PhoneNormalizer::international(
                $this->input('to'),
                (string) config('gateway.default_country_code', '62'),
            )]);
        }
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'regex:/\A\+?[1-9][0-9]{7,14}\z/'],
            'type' => ['required', Rule::in(['text', 'image', 'document', 'voice'])],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:10000'],
            'media_id' => ['required_unless:type,text', 'prohibited_if:type,text', 'nullable', 'uuid'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
