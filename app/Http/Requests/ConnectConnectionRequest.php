<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v2/connections/{id}/connect and /retry.
 */
class ConnectConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('client_application');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'auth_method' => ['sometimes', 'nullable', Rule::in(['qr', 'pairing'])],
            'phone' => ['required_if:auth_method,pairing', 'nullable', 'string', 'regex:/\A\+?[1-9][0-9]{6,14}\z/'],
            'provider' => ['sometimes', 'array'],
            'provider.configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Corrected provider settings for a provider route; omitted keys keep their stored value.
     *
     * @return array<string, mixed>
     */
    public function providerConfiguration(): array
    {
        $configuration = $this->validated('provider.configuration');

        return is_array($configuration) ? $configuration : [];
    }
}
