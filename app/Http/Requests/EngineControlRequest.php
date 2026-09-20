<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EngineControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('client_application');
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('engine.v2.sessions.create')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'engine.v2.sessions.create' => [
                'idempotency_key' => ['required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
                'name' => ['sometimes', 'string', 'min:1', 'max:120'],
                'external_reference' => ['sometimes', 'string', 'min:1', 'max:160'],
                'start' => ['sometimes', 'boolean'], 'auth_method' => ['sometimes', 'in:qr,pairing'],
                'phone' => ['required_if:auth_method,pairing', 'nullable', 'string', 'regex:/\A\+?[1-9][0-9]{6,14}\z/'],
            ],
            'engine.v2.sessions.rename' => ['name' => ['required', 'string', 'min:1', 'max:120']],
            'engine.v2.sessions.pairing' => ['phone' => ['required', 'string', 'regex:/\A\+?[1-9][0-9]{6,14}\z/']],
            'engine.v2.integration.update' => ['configured_limit' => ['required', 'integer', 'min:0']],
            'engine.v2.webhook' => ['url' => ['required', 'url', 'max:2048'], 'secret' => ['required', 'string', 'min:32', 'max:256']],
            default => [],
        };
    }
}
