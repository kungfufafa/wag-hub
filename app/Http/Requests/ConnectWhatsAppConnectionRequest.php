<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ConnectWhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(['qr', 'pairing'])],
            'phone' => ['required_if:mode,pairing', 'nullable', 'string', 'max:32'],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'error' => [
                'code' => 'validation_failed',
                'retryable' => false,
            ],
            'errors' => $validator->errors()->toArray(),
            'request_id' => $this->attributes->get('request_id'),
        ], 422));
    }
}
