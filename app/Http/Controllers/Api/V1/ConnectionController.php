<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\WhatsAppConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectWhatsAppConnectionRequest;
use App\Http\Requests\StoreConnectionRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ApplicationErrorMapper;
use App\Services\Connections\ConnectionPresenter;
use App\Services\Connections\ConnectionProvisioner;
use App\Services\Connections\ConnectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function __construct(
        private ConnectionResolver $resolver,
        private ConnectionProvisioner $provisioner,
        private ConnectionPresenter $presenter,
        private ApplicationErrorMapper $errors,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $connections = array_map(
            fn (WhatsAppConnection $connection): array => $this->presenter->toArray($connection),
            $this->resolver->list($application),
        );

        return response()->json([
            'data' => $connections,
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        try {
            $connection = $this->provisioner->provision($this->application($request), $request->payload());
        } catch (WhatsAppConnectionException $exception) {
            return $this->failure($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includePairingSecrets: true),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        try {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
        } catch (WhatsAppConnectionException $exception) {
            return $this->failure($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includePairingSecrets: true),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function connect(ConnectWhatsAppConnectionRequest $request, string $uuid): JsonResponse
    {
        try {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->connect($connection, $request->validated());
        } catch (WhatsAppConnectionException $exception) {
            return $this->failure($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includePairingSecrets: true),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        try {
            $connection = $this->resolver->resolve($this->application($request), $uuid);
            $connection = $this->provisioner->retry($connection);
        } catch (WhatsAppConnectionException $exception) {
            return $this->failure($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includePairingSecrets: true),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function test(StoreMessageRequest $request, string $uuid): JsonResponse
    {
        $request->merge([
            'connection_id' => $uuid,
            'mode' => $request->input('mode', 'sync'),
            'purpose' => $request->input('purpose', 'notification'),
        ]);

        return app(MessageController::class)->store($request);
    }

    private function application(Request $request): ClientApplication
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        return $application;
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }

    private function failure(Request $request, WhatsAppConnectionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'error' => $this->errors->applicationError(
                $exception->errorCode,
                $exception->retryable,
                $exception->auditId ?? $this->requestId($request),
                true,
            ),
            'request_id' => $this->requestId($request),
        ], $exception->statusCode);
    }
}
