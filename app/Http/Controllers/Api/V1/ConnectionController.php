<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupConnectionRequest;
use App\Http\Requests\StoreConnectionRequest;
use App\Http\Requests\TestConnectionRequest;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Services\Connection\ConnectionFallbackManager;
use App\Services\Connection\ConnectionMessageSender;
use App\Services\Connection\ConnectionPresenter;
use App\Services\Connection\ConnectionProvisioner;
use App\Services\Connection\ConnectionStatusResolver;
use App\Services\GatewayMessageDispatcher;
use App\Services\IntegrationPack;
use App\Support\PayloadHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ConnectionController extends Controller
{
    public function __construct(
        private readonly ConnectionProvisioner $provisioner,
        private readonly ConnectionPresenter $presenter,
        private readonly ConnectionStatusResolver $statusResolver,
        private readonly ConnectionMessageSender $messageSender,
        private readonly PayloadHasher $hasher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        $connections = $application->whatsappConnections()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($connection) => $this->presenter->toArray($this->statusResolver->refresh($connection)));

        return response()->json([
            'data' => $connections,
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $connection = match ($request->validated('type')) {
                'managed_number' => $this->provisioner->createManagedNumber(
                    application: $application,
                    name: (string) $request->validated('name'),
                    sessionId: $request->validated('session_id'),
                    makeDefault: (bool) $request->boolean('is_default'),
                ),
                'provider_route' => $this->provisioner->createProviderRoute(
                    application: $application,
                    name: (string) $request->validated('name'),
                    driver: (string) $request->validated('driver'),
                    configuration: $request->validated('configuration', []),
                    makeDefault: (bool) $request->boolean('is_default'),
                ),
                default => throw new ConnectionException('Tipe koneksi tidak dikenali.', 422, 'capability_not_supported'),
            };
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function show(Request $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);
            $connection = $this->statusResolver->refresh($connection);
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includeSetupSecrets: true),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function setup(SetupConnectionRequest $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);

            if ($connection->isManagedNumber()) {
                $connection = $this->provisioner->startManagedSetup(
                    connection: $connection,
                    mode: (string) $request->validated('mode'),
                    phone: $request->validated('phone'),
                );
            } else {
                $connection = $this->provisioner->validateProviderRoute($connection);
            }
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection, includeSetupSecrets: true),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function test(TestConnectionRequest $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');
        $idempotencyKey = 'connection-test:'.(string) Str::uuid();

        $payload = [
            'recipient' => ['type' => 'phone', 'value' => (string) $request->validated('recipient')],
            'message' => [
                'type' => 'text',
                'text' => (string) ($request->validated('text') ?: 'WAG Hub test message'),
            ],
            'purpose' => 'notification',
            'mode' => 'sync',
            'metadata' => ['test' => true],
        ];

        $payloadHash = $this->hasher->hash($payload, (string) config('app.key'));

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);
            $message = $this->messageSender->send(
                application: $application,
                payload: $payload,
                idempotencyKey: $idempotencyKey,
                payloadHash: $payloadHash,
                correlationId: $this->requestId($request),
                connection: $connection,
            );

            if ((string) $message->mode === 'sync' && (string) $message->status !== 'provider_accepted') {
                $message = app(GatewayMessageDispatcher::class)->reconcile($message);
            }
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => [
                'message_id' => (string) $message->uuid,
                'status' => (string) $message->status,
                'connection' => $this->presenter->toArray($connection->fresh() ?? $connection),
            ],
            'request_id' => $this->requestId($request),
        ], (string) $message->status === 'provider_accepted' ? 201 : 502);
    }

    public function fallbacks(Request $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);
            $steps = app(ConnectionFallbackManager::class)->listSteps($connection);
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => $steps,
            'request_id' => $this->requestId($request),
        ]);
    }

    public function addFallback(Request $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        $providerSlug = (string) $request->validate([
            'provider' => ['required', 'string', 'max:80'],
        ])['provider'];

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);
            $provider = ProviderAccount::query()->where('slug', $providerSlug)->where('is_active', true)->first();

            if ($provider === null) {
                throw new ConnectionException('Provider tidak ditemukan.', 404, 'connection_not_ready');
            }

            $connection = app(ConnectionFallbackManager::class)->addFallback($connection, $provider);
            $connection = $this->statusResolver->refresh($connection);
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        return response()->json([
            'data' => $this->presenter->toArray($connection),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function integration(Request $request, string $connectionId): JsonResponse
    {
        /** @var ClientApplication $application */
        $application = $request->attributes->get('client_application');

        try {
            $connection = $this->messageSender->resolveConnection($application, $connectionId);
        } catch (ConnectionException $exception) {
            return $this->connectionError($request, $exception);
        }

        $pack = app(IntegrationPack::class);
        $baseUrl = $pack->hubUrl();

        return response()->json([
            'data' => [
                'env' => implode("\n", [
                    'WAG_URL='.$baseUrl,
                    'WAG_TOKEN=<your-application-token>',
                    'WAG_CONNECTION_ID='.(string) $connection->uuid,
                ]),
                'examples' => [
                    'curl' => 'curl -X POST '.$baseUrl.'/api/v1/messages \\'."\n"
                        .'  -H "Authorization: Bearer $WAG_TOKEN" \\'."\n"
                        .'  -H "Idempotency-Key: test-1" \\'."\n"
                        .'  -H "Content-Type: application/json" \\'."\n"
                        ."  -d '{\"connection_id\":\"{$connection->uuid}\",\"recipient\":{\"type\":\"phone\",\"value\":\"6281234567890\"},\"message\":{\"type\":\"text\",\"text\":\"Hello\"},\"purpose\":\"notification\",\"mode\":\"sync\"}'",
                    'php' => '$client->messages()->send(recipient: "6281234567890", text: "Hello", idempotencyKey: "test-1");',
                    'node' => 'await wag.messages.send({ recipient: "6281234567890", message: { type: "text", text: "Hello" }, idempotencyKey: "test-1" });',
                ],
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    private function connectionError(Request $request, ConnectionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'error' => $exception->toErrorPayload(),
            'request_id' => $this->requestId($request),
        ], $exception->httpStatus);
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }
}
