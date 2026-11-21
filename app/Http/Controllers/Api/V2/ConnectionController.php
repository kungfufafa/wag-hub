<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectConnectionRequest;
use App\Http\Requests\SendEngineMessageRequest;
use App\Http\Requests\StoreConnectionRequest;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ApplicationErrorMapper;
use App\Services\Connections\ConnectionMessenger;
use App\Services\Connections\ConnectionPresenter;
use App\Services\Connections\ConnectionProvisioner;
use App\Services\Connections\ConnectionResolver;
use App\Services\Engine\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Connection API: one WhatsApp connection per sending capability. A managed number is a
 * v2 engine session (visible in /api/v2/sessions); a provider route is a routed gateway path.
 */
class ConnectionController extends Controller
{
    public function __construct(
        private ConnectionResolver $resolver,
        private ConnectionProvisioner $provisioner,
        private ConnectionPresenter $presenter,
        private ConnectionMessenger $messenger,
        private ApplicationErrorMapper $errors,
        private SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $connections = array_map(
            fn (WhatsAppConnection $connection): array => $this->presenter->toArray($connection),
            $this->resolver->list($this->application($request)),
        );

        return response()->json(['data' => $connections]);
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $provisioned = $this->provisioner->provision(
                $this->application($request),
                $request->payload(),
                $request->idempotencyKey(),
            );

            return $this->connectionResponse($request, $provisioned->connection, 201);
        });
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $session = $connection->isManaged() ? $connection->ownedEngineSession() : null;

            if ($session !== null && ! $session->trashed()) {
                // Same live observation as GET /api/v2/sessions/{id}.
                $this->sessions->refresh($session);
                $connection->unsetRelation('engineSession');
            }

            return $this->connectionResponse($request, $connection, 200);
        });
    }

    public function connect(ConnectConnectionRequest $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->connect($connection, $request->safe()->only(['auth_method', 'phone']));

            return $this->connectionResponse($request, $connection, $connection->isManaged() ? 202 : 200);
        });
    }

    public function retry(ConnectConnectionRequest $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->retry($connection, $request->providerConfiguration());

            return $this->connectionResponse($request, $connection, $connection->isManaged() ? 202 : 200);
        });
    }

    public function send(SendEngineMessageRequest $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $delivery = $this->messenger->send(
                $connection,
                $request->validated(),
                (string) $request->header('Idempotency-Key', ''),
            );

            if ($delivery->accepted()) {
                return response()->json(['data' => $delivery->data], $delivery->status);
            }

            return response()->json([
                'message' => $delivery->message,
                'error' => $delivery->error,
                'data' => $delivery->data,
            ], $delivery->status);
        });
    }

    /**
     * A QR or pairing code can link a device to the number, so like GET sessions/{id}/auth/qr it is
     * only handed to credentials that may manage sessions; read-only tokens see the status alone.
     */
    private function connectionResponse(Request $request, WhatsAppConnection $connection, int $status): JsonResponse
    {
        $secrets = $this->canManageSessions($request);

        return response()->json(['data' => $this->presenter->toArray($connection, includePairingSecrets: $secrets)], $status)
            ->header('Cache-Control', 'no-store');
    }

    private function canManageSessions(Request $request): bool
    {
        $credential = $request->attributes->get('api_credential');

        if (! $credential instanceof ApiCredential) {
            return false;
        }

        $abilities = $credential->abilities ?? [];

        return is_array($abilities) && (in_array('*', $abilities, true) || in_array('sessions:manage', $abilities, true));
    }

    private function application(Request $request): ClientApplication
    {
        $application = $request->attributes->get('client_application');

        if (! $application instanceof ClientApplication) {
            throw new WhatsAppEngineException('Unauthenticated.', 401, 'unauthenticated');
        }

        return $application;
    }

    /**
     * Render connection and engine failures in the v2 error shape: {message, error: {code, retryable}}.
     */
    private function attempt(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (WhatsAppConnectionException|WhatsAppEngineException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => $this->errors->forConnection($exception->errorCode, $exception->retryable),
            ], $exception->statusCode);
        }
    }
}
