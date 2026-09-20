<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Jobs\ProcessEngineOperation;
use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Models\EngineOperation;
use App\Models\EngineSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SessionService
{
    public function __construct(private RunnerClient $runner) {}

    public function integration(ClientApplication $application): EngineIntegration
    {
        return DB::transaction(function () use ($application): EngineIntegration {
            ClientApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            return EngineIntegration::query()->firstOrCreate(['client_application_id' => $application->id], [
                'configured_limit' => min(config('engine_v2.default_limit'), config('engine_v2.default_ceiling')),
                'maximum_allowed' => config('engine_v2.default_ceiling'),
            ]);
        });
    }

    public function find(ClientApplication $application, string $id): EngineSession
    {
        return EngineSession::query()->where('client_application_id', $application->id)->where('uuid', $id)->firstOrFail();
    }

    public function create(ClientApplication $application, string $key, array $input): EngineSession
    {
        $fingerprint = hash('sha256', json_encode([
            'name' => $input['name'] ?? null, 'start' => $input['start'] ?? false,
            'auth_method' => $input['auth_method'] ?? 'qr', 'phone' => $input['phone'] ?? null,
            'external_reference' => $input['external_reference'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($application, $key, $input, $fingerprint): EngineSession {
            $integration = $this->integration($application);
            $existing = EngineSession::withTrashed()->where('client_application_id', $application->id)
                ->where(function ($query) use ($key, $input): void {
                    $query->where('creation_key', $key);
                    if (isset($input['external_reference'])) {
                        $query->orWhere('external_reference', $input['external_reference']);
                    }
                })->first();
            if ($existing !== null) {
                if (! hash_equals($existing->creation_hash, $fingerprint)) {
                    throw new WhatsAppEngineException('This idempotency key belongs to a different request.', 409, 'idempotency_conflict');
                }
                if ($existing->trashed()) {
                    throw new WhatsAppEngineException('This connection attempt was removed. Start a new attempt.', 410, 'session_removed');
                }

                return $existing;
            }
            $used = EngineSession::query()->where('client_application_id', $application->id)->count();
            if ($used >= min($integration->configured_limit, $integration->maximum_allowed)) {
                throw new WhatsAppEngineException('The account limit has been reached.', 409, 'capacity_exceeded');
            }
            $uuid = (string) Str::uuid();
            $session = EngineSession::query()->create([
                'uuid' => $uuid, 'runner_id' => $uuid, 'client_application_id' => $application->id,
                'name' => $input['name'] ?? 'WhatsApp '.($used + 1), 'creation_key' => $key,
                'external_reference' => $input['external_reference'] ?? null,
                'creation_hash' => $fingerprint, 'status' => 'STOPPED', 'desired_state' => 'STOPPED',
                'provisioning_expires_at' => ($input['start'] ?? false) ? now()->addMinutes(config('engine_v2.provisioning_minutes')) : null,
            ]);
            if ($input['start'] ?? false) {
                $this->command($session, 'start', ['mode' => $input['auth_method'] ?? 'qr', 'phone' => $input['phone'] ?? null]);
            } else {
                $this->publish($session);
            }

            return $session->fresh();
        });
    }

    public function command(EngineSession $session, string $action, array $payload = []): EngineOperation
    {
        if (! in_array($action, ['start', 'stop', 'logout', 'delete', 'pairing-code'], true)) {
            throw new WhatsAppEngineException('Unknown session action.', 422, 'invalid_action');
        }
        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== null);

        return DB::transaction(function () use ($session, $action, $payload): EngineOperation {
            $session = EngineSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $pending = $session->pendingOperation;
            if ($pending && in_array($pending->status, ['pending', 'processing'], true)) {
                if ($pending->action === $action && ($pending->payload ?? []) === $payload) {
                    return $pending;
                }
                $pending->update(['status' => 'superseded', 'completed_at' => now(), 'payload' => null]);
            }
            $generation = $session->generation + 1;
            $operation = EngineOperation::query()->create([
                'client_application_id' => $session->client_application_id, 'engine_session_id' => $session->id,
                'action' => $action, 'generation' => $generation, 'payload' => $payload,
                'status' => 'pending', 'available_at' => now(),
            ]);
            $session->update([
                'generation' => $generation, 'runner_revision' => 0, 'revision' => $session->revision + 1,
                'desired_state' => in_array($action, ['start', 'pairing-code'], true) ? 'RUNNING' : 'STOPPED',
                'pending_operation_id' => $operation->id,
            ]);
            $this->publish($session);
            DB::afterCommit(function () use ($operation): void {
                try {
                    ProcessEngineOperation::dispatch($operation->id)->onConnection(config('engine_v2.queue_connection', 'database'))->onQueue('engine-control');
                } catch (Throwable $error) {
                    report($error);
                    // The persisted command is recovered by engine:control-tick.
                }
            });

            return $operation;
        });
    }

    public function execute(EngineOperation $operation): void
    {
        $operation->refresh();
        $session = $operation->session;
        if (! in_array($operation->status, ['pending', 'processing'], true) || $session->generation !== $operation->generation) {
            return;
        }
        $operation->update(['status' => 'processing', 'attempts' => $operation->attempts + 1]);
        $path = '/sessions/'.rawurlencode($session->runner_id);
        $action = $operation->action;
        try {
            $response = $this->runner->request($action === 'delete' ? 'DELETE' : 'POST', $path.match ($action) {
                'delete' => '', 'pairing-code' => '/auth/pairing-code', default => '/'.$action,
            }, array_merge($operation->payload ?? [], ['generation' => $operation->generation]));
            if (($response['ok'] ?? true) === false) {
                throw new WhatsAppEngineException('The runner has not completed this action.', 503, $response['error_code'] ?? 'operation_incomplete', true);
            }
            if (isset($response['generation']) && (int) $response['generation'] !== $operation->generation) {
                throw new WhatsAppEngineException('Runner response belongs to another session generation.', 409, 'session_generation_mismatch');
            }
            if (! in_array($response['canonical_status'] ?? $response['status'] ?? null,
                ['STOPPED', 'STARTING', 'SCAN_QR_CODE', 'WORKING', 'FAILED', 'connected', 'qr', 'pairing', 'connecting', 'disconnected'], true)) {
                throw new WhatsAppEngineException('Runner returned an invalid session observation.', 502, 'engine_response_invalid', true);
            }
            if (in_array($action, ['stop', 'logout', 'delete'], true)
                && ! in_array($response['canonical_status'] ?? $response['status'], ['STOPPED', 'disconnected'], true)) {
                throw new WhatsAppEngineException('The runner has not confirmed socket teardown.', 503, 'operation_incomplete', true);
            }
            DB::transaction(function () use ($operation, $response, $action): void {
                $session = EngineSession::withTrashed()->whereKey($operation->engine_session_id)->lockForUpdate()->firstOrFail();
                if ($session->generation !== $operation->generation) {
                    $operation->update(['status' => 'superseded', 'completed_at' => now(), 'payload' => null]);

                    return;
                }
                $this->observe($session, $response);
                $session->refresh();
                $session->update(['pending_operation_id' => null, 'revision' => $session->revision + 1]);
                $operation->update(['status' => 'completed', 'completed_at' => now(), 'payload' => null, 'last_error' => null,
                    'result' => ['remote_logout_confirmed' => $response['logout_confirmed'] ?? null]]);
                if ($action === 'delete') {
                    $session->delete();
                }
                $this->publish($session->fresh());
            });
        } catch (WhatsAppEngineException $error) {
            DB::transaction(function () use ($session, $operation, $error): void {
                $current = EngineSession::withTrashed()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($current->generation !== $operation->generation) {
                    return;
                }
                $terminal = $error->statusCode >= 400 && $error->statusCode < 500;
                $operation->update(['status' => $terminal ? 'failed' : 'pending', 'last_error' => $error->errorCode,
                    'available_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $operation->attempts)))),
                    'completed_at' => $terminal ? now() : null]);
                $current->update(['engine_available' => false, 'last_error' => $error->errorCode,
                    'pending_operation_id' => $terminal ? null : $operation->id, 'revision' => $current->revision + 1]);
                $this->publish($current);
            });
        }
    }

    public function refresh(EngineSession $session): EngineSession
    {
        if ($session->generation === 0 && $session->external_reference === null) {
            return $session;
        }
        try {
            $response = $this->runner->request('GET', '/sessions/'.rawurlencode($session->runner_id));
            $requestedGeneration = $session->generation;
            $session = DB::transaction(function () use ($session, $response, $requestedGeneration): EngineSession {
                $current = EngineSession::withTrashed()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $before = $current->revision;
                $current = $this->observe($current, [...$response, 'generation' => $response['generation'] ?? $requestedGeneration]);
                if ($current->revision !== $before) {
                    $this->publish($current);
                }

                return $current;
            });
        } catch (WhatsAppEngineException $error) {
            $session = DB::transaction(function () use ($session, $error): EngineSession {
                $current = EngineSession::withTrashed()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                // A failed old poll must not overwrite a newer command or successful observation.
                if ($current->generation !== $session->generation || $current->revision !== $session->revision || $current->trashed()) {
                    return $current;
                }
                $current->fill(['engine_available' => false, 'last_error' => $error->errorCode]);
                if ($current->isDirty()) {
                    $current->revision++;
                    $current->save();
                    $this->publish($current);
                }

                return $current;
            });
        }

        return $session->fresh();
    }

    public function observe(EngineSession $session, array $snapshot, ?CarbonInterface $observedAt = null): EngineSession
    {
        return DB::transaction(function () use ($session, $snapshot, $observedAt): EngineSession {
            $session = EngineSession::withTrashed()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $generation = (int) ($snapshot['generation'] ?? $session->generation);
            $revision = (int) ($snapshot['revision'] ?? $session->runner_revision);
            if ($generation !== $session->generation || $revision < $session->runner_revision || $session->trashed()) {
                return $session;
            }
            $raw = $snapshot['canonical_status'] ?? $snapshot['status'] ?? null;
            $status = match ($raw) {
                'STOPPED', 'STARTING', 'SCAN_QR_CODE', 'WORKING', 'FAILED' => $raw,
                'connected' => 'WORKING', 'qr', 'pairing' => 'SCAN_QR_CODE',
                'connecting' => 'STARTING', 'disconnected' => 'STOPPED', default => null,
            };
            if ($status === null) {
                return $session;
            }
            $values = [
                'status' => $status, 'engine_available' => true, 'runner_revision' => $revision,
                'auth_method' => $status === 'SCAN_QR_CODE' ? ($snapshot['auth_method'] ?? ($raw === 'pairing' ? 'pairing' : 'qr')) : null,
                'last_error' => $snapshot['error_code'] ?? $snapshot['last_error'] ?? null,
            ];
            if ($status === 'WORKING') {
                $values['phone_number'] = $snapshot['phone_number'] ?? $snapshot['phone'] ?? $session->phone_number;
                $values['authenticated_at'] = $session->authenticated_at ?? now();
                $values['provisioning_expires_at'] = null;
            }
            $session->fill($values);
            if ($session->isDirty()) {
                $session->revision++;
            }
            $session->observed_at = $observedAt && $observedAt->lessThan(now()) ? $observedAt : now();
            $session->save();

            return $session;
        });
    }

    public function snapshot(EngineSession $session): array
    {
        return [
            'id' => $session->uuid, 'name' => $session->name, 'external_reference' => $session->external_reference,
            'status' => $session->status, 'desired_state' => $session->desired_state,
            'phone_number' => $session->phone_number, 'auth_method' => $session->auth_method,
            'revision' => $session->revision, 'observed_at' => $session->observed_at?->toISOString(),
            'stale' => ! $session->engine_available || ! $session->observed_at || $session->observed_at->lt(now()->subSeconds(config('engine_v2.stale_after_seconds', 30))),
            'engine_available' => $session->engine_available,
            'pending_operation' => $session->pending_operation_id ? $this->operationSnapshot($session->pendingOperation) : null,
            'last_error' => $session->last_error, 'deleted' => $session->trashed(),
        ];
    }

    public function operationSnapshot(?EngineOperation $operation): ?array
    {
        return $operation ? ['id' => $operation->uuid, 'action' => $operation->action, 'status' => $operation->status,
            'last_error' => $operation->last_error, 'result' => $operation->result,
            'completed_at' => $operation->completed_at?->toISOString()] : null;
    }

    public function challenge(EngineSession $session): array
    {
        if ($session->desired_state !== 'RUNNING') {
            throw new WhatsAppEngineException('Start the session before requesting authentication.', 409, 'session_stopped');
        }

        return $this->runner->request('GET', '/sessions/'.rawurlencode($session->runner_id).'/auth/qr');
    }

    private function publish(EngineSession $session): void
    {
        app(EngineEventService::class)->publishSession($session);
    }
}
