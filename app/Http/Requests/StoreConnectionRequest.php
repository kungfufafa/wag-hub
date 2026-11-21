<?php

namespace App\Http\Requests;

use App\Domain\Connections\ConnectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v2/connections. Idempotency-Key is required, as on POST /api/v2/sessions.
 */
class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('client_application');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in([
                ConnectionType::ManagedNumber->value,
                ConnectionType::ProviderRoute->value,
            ])],
            'is_default' => ['sometimes', 'boolean'],
            'auth_method' => ['sometimes', 'nullable', Rule::in(['qr', 'pairing'])],
            'phone' => ['required_if:auth_method,pairing', 'nullable', 'string', 'regex:/\A\+?[1-9][0-9]{6,14}\z/'],
            'provider' => ['required_if:type,'.ConnectionType::ProviderRoute->value, 'prohibited_if:type,'.ConnectionType::ManagedNumber->value, 'array'],
            'provider.driver' => [
                'required_if:type,'.ConnectionType::ProviderRoute->value,
                Rule::in(['waha', 'fonnte', 'gowa', 'waba']),
            ],
            'provider.configuration' => ['nullable', 'array'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->except('idempotency_key');
    }
}
