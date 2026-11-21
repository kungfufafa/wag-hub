<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectConnectionRequest;
use App\Http\Requests\SendEngineMessageRequest;
use App\Http\Requests\StoreConnectionRequest;
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

            return $this->connectionResponse($provisioned->connection, 201);
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

            return $this->connectionResponse($connection, 200);
        });
    }

    public function connect(ConnectConnectionRequest $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->connect($connection, $request->safe()->only(['auth_method', 'phone']));

            return $this->connectionResponse($connection, $connection->isManaged() ? 202 : 200);
        });
    }

    public function retry(ConnectConnectionRequest $request, string $uuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $uuid): JsonResponse {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->retry($connection, $request->providerConfiguration());

            return $this->connectionResponse($connection, $connection->isManaged() ? 202 : 200);
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

    private function connectionResponse(WhatsAppConnection $connection, int $status): JsonResponse
    {
        return response()->json(['data' => $this->presenter->toArray($connection, includePairingSecrets: true)], $status)
            ->header('Cache-Control', 'no-store');
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
