<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::in(['managed_number', 'provider_route'])],
            'is_default' => ['sometimes', 'boolean'],
            'session_id' => ['nullable', 'string', 'regex:/\A[a-z][a-z0-9-]{1,46}\z/', 'not_regex:/--/', 'not_regex:/-$/'],
            'driver' => ['required_if:type,provider_route', Rule::in(['wag_hub', 'waha', 'fonnte', 'gowa', 'waba'])],
            'configuration' => ['required_if:type,provider_route', 'array'],
            'configuration.base_url' => ['nullable', 'string', 'max:2048'],
            'configuration.endpoint' => ['nullable', 'string', 'max:2048'],
            'configuration.api_key' => ['nullable', 'string', 'max:500'],
            'configuration.session' => ['nullable', 'string', 'max:120'],
            'configuration.token' => ['nullable', 'string', 'max:500'],
            'configuration.username' => ['nullable', 'string', 'max:120'],
            'configuration.password' => ['nullable', 'string', 'max:500'],
            'configuration.device_id' => ['nullable', 'string', 'max:120'],
            'configuration.phone_number_id' => ['nullable', 'string', 'max:120'],
            'configuration.access_token' => ['nullable', 'string', 'max:500'],
            'configuration.api_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
