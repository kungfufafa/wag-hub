<?php

namespace App\Http\Requests;

/**
 * Send through the Connection API (POST /api/v2/messages and /connections/{id}/messages).
 *
 * Beyond the session send contract it accepts media by URL, either as `media_url` with a type or
 * with the shortcut keys {"image": url} / {"document": url} / {"voice": url}, and an optional
 * `connection` id (POST /api/v2/messages only; omitted means the application's default).
 */
class SendMessageRequest extends SendEngineMessageRequest
{
    private const MEDIA_SHORTCUTS = ['image', 'document', 'voice'];

    protected function prepareForValidation(): void
    {
        foreach (self::MEDIA_SHORTCUTS as $kind) {
            if (is_string($this->input($kind)) && ! $this->has('type') && ! $this->has('media_url')) {
                $this->merge(['type' => $kind, 'media_url' => $this->input($kind)]);
                break;
            }
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['media_id'] = ['nullable', 'uuid', 'prohibited_if:type,text', 'prohibits:media_url'];
        $rules['media_url'] = ['nullable', 'string', 'max:2048', 'url:http,https', 'prohibited_if:type,text'];
        $rules['connection'] = ['nullable', 'uuid'];

        foreach (self::MEDIA_SHORTCUTS as $kind) {
            $rules[$kind] = ['nullable', 'string'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type', 'text') !== 'text' && blank($this->input('media_id')) && blank($this->input('media_url'))) {
                $validator->errors()->add('media_url', 'Isi media_url (atau image/document/voice) atau media_id untuk pesan media.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return array_intersect_key($validated, array_flip(['to', 'type', 'text', 'media_id', 'media_url', 'expires_at']));
    }
}
