<?php

namespace App\Http\Requests;

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
