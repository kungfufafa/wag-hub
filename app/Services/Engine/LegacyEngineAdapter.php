<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Models\ClientApplication;
use App\Models\EngineMessage;
use App\Models\EngineSession;

class LegacyEngineAdapter
{
    public function __construct(private SessionService $sessions, private EngineTransportService $transport) {}

    public function mapped(ClientApplication $application, string $reference): ?EngineSession
    {
        return EngineSession::withTrashed()->where('client_application_id', $application->id)->where('external_reference', $reference)->first();
    }

    public function snapshot(EngineSession $session): array
    {
        $snapshot = $this->sessions->snapshot($session);
        $status = match ($snapshot['status']) {
            'WORKING' => 'connected', 'STARTING' => 'connecting',
            'SCAN_QR_CODE' => $snapshot['auth_method'] === 'pairing' ? 'pairing' : 'qr',
            'STOPPED', 'FAILED' => 'disconnected', default => 'unknown',
        };
        if ($snapshot['stale'] || $snapshot['pending_operation']) {
            $status = 'unknown';
        }
        $challenge = [];
        if (! $session->trashed() && $snapshot['status'] === 'SCAN_QR_CODE' && ! $snapshot['stale']) {
            $challenge = $this->sessions->challenge($session);
        }

        return ['ok' => true, 'id' => $session->external_reference, 'status' => $status,
            'canonical_status' => $snapshot['status'], 'stale' => $snapshot['stale'],
            'engine_available' => $snapshot['engine_available'], 'pending_operation' => $snapshot['pending_operation'],
            'phone' => $snapshot['phone_number'], 'qr' => $challenge['qr'] ?? null,
            'pairing_code' => $challenge['pairing_code'] ?? null];
    }

    public function action(EngineSession $session, string $action, array $payload = []): array
    {
        if ($session->trashed()) {
            throw new WhatsAppEngineException('This session has been removed.', 410, 'session_removed');
        }
        $operation = $this->sessions->command($session, $action, $payload);
        $this->sessions->execute($operation);
        $operation->refresh();
        if ($operation->status !== 'completed') {
            throw new WhatsAppEngineException('The operation is pending confirmation from the engine.', 503, 'operation_pending', true);
        }

        return $this->snapshot($session->fresh()) + ['logout_confirmed' => $operation->result['remote_logout_confirmed'] ?? false];
    }

    public function send(ClientApplication $application, EngineSession $session, string $phone, string $text, string $key): array
    {
        $message = $this->transport->accept($application, $session->uuid, ['to' => $phone, 'type' => 'text', 'text' => $text], $this->key($session, $key));
        if ($message->transport_status === 'queued') {
            $this->transport->dispatch($message->id);
        }

        return $this->result($message->fresh());
    }

    public function message(ClientApplication $application, EngineSession $session, string $key): array
    {
        $message = EngineMessage::query()->where('client_application_id', $application->id)->where('idempotency_key', $this->key($session, $key))->first();
        if ($message === null) {
            throw new WhatsAppEngineException('Message not found.', 404, 'message_not_found');
        }

        return $this->result($this->transport->reconcile($message));
    }

    private function key(EngineSession $session, string $key): string
    {
        return 'legacy:'.hash('sha256', $session->external_reference.'|'.$key);
    }

    private function result(EngineMessage $message): array
    {
        return ['ok' => $message->transport_status === 'accepted',
            'status' => match ($message->transport_status) {
                'accepted' => 'sent', 'failed', 'expired' => 'failed', default => 'unknown'
            },
            'id' => $message->provider_message_id ?? $message->uuid, 'retryable' => false,
            'error_code' => $message->error_code];
    }
}
