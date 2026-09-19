<?php

namespace App\Http\Requests;

use App\Domain\Connections\ConnectionType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreConnectionRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in([
                ConnectionType::ManagedNumber->value,
                ConnectionType::ProviderRoute->value,
            ])],
            'is_default' => ['sometimes', 'boolean'],
            'mode' => ['nullable', Rule::in(['qr', 'pairing'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'provider' => ['required_if:type,'.ConnectionType::ProviderRoute->value, 'array'],
            'provider.driver' => [
                'required_if:type,'.ConnectionType::ProviderRoute->value,
                Rule::in(['wag_hub', 'waha', 'fonnte', 'gowa', 'waba']),
            ],
            'provider.configuration' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
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
