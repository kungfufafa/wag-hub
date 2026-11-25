# WAG Hub threat model

WAG Hub is a self-hosted WhatsApp gateway. Client applications such as CESA and Shelf use scoped API credentials to send OTP, transactional and notification messages. The Hub manages linked numbers, routes outbound messages through Baileys or provider integrations, processes inbound events, and gives administrators access to the inbox and routing configuration.

## Components and trust boundaries

| Component | Boundary | Code |
| --- | --- | --- |
| Client API | Client applications cross into the Hub using bearer credentials. Each client application is an authorization boundary. | [API routes](routes/api.php), [v2 routes](routes/v2.php), [legacy engine routes](routes/engine.php), [API controllers](app/Http/Controllers/Api), [HTTP middleware](app/Http/Middleware), [engine session service](app/Services/Engine/SessionService.php) |
| Provider Webhooks | External providers submit events that can affect inbox and session state and trigger outbound replies. Messages from WhatsApp contacts remain untrusted input. | [Webhook routes](routes/webhooks.php), [WhatsApp webhook controller](app/Http/Controllers/Webhooks/WhatsAppWebhookController.php), [WhatsApp integration code](app/Infrastructure/WhatsApp), [auto-responder](app/Services/AutoResponder.php) |
| Admin Panel | Operators enter through a browser session. The panel can manage client applications, credentials, routing and linked numbers. Diagnostics expose logs and queue operations. | [Filament](app/Filament), [panel access](app/Models/User.php), [Log Viewer](app/Providers/AppServiceProvider.php), [Horizon](app/Providers/HorizonServiceProvider.php) |
| Attachments | Authenticated clients use API media endpoints. Providers can fetch attachments using signed download URLs. | [Web routes](routes/web.php), [download controller](app/Http/Controllers/AttachmentDownloadController.php), [attachment access middleware](app/Http/Middleware/EnsureAttachmentAccess.php), [v1 attachment API](app/Http/Controllers/Api/V1/AttachmentController.php), [v2 transport API](app/Http/Controllers/Api/V2/TransportController.php) |
| Dispatcher & Providers | Hub workers process queued operations and make outbound calls to provider endpoints and client webhook destinations. | [Jobs](app/Jobs), [services](app/Services), [engine webhook delivery](app/Services/Engine/EngineEventService.php), [webhook destination checks](app/Services/Engine/WebhookDestination.php) |
| Baileys Runner | The Hub and runner share a service credential for control requests. The runner holds the authentication state for linked WhatsApp numbers. | [Runner](engine/baileys-runner), [runner client](app/Services/Engine/RunnerClient.php), [desired sessions endpoint](app/Http/Controllers/Api/V2/IntegrationController.php) |
| Infrastructure | The reverse proxy, containers, queue workers, database and volumes separate external traffic from internal services and persistent state. | [v2 Compose deployment](engine/docker-compose.v2.yml), [legacy Compose deployment](engine/docker-compose.yml), [deployment guide](docs/DEPLOYMENT_ID.md) |

## Entry points and existing controls

- **Client API.** `/api/v1/*`, `/api/v2/*` and `/engine/*` require an API credential. Authentication checks a SHA-256 token hash, revocation, expiration and whether the client application is active. Route middleware checks abilities and rate limits. Client-owned resources such as v2 sessions and v1 messages are scoped by `client_application_id`. Send and create operations use idempotency keys where required by the endpoint. The legacy `/engine/t/{engineToken}/*` route also accepts a token in the URL path, which makes URL handling by proxies and logs part of the credential boundary. Legacy access can be retired per integration.
- **Provider webhooks.** `/webhooks/whatsapp/{provider}` accepts GET verification requests and POST events. The application checks a per-account token for non-WABA accounts when a secret is configured. WABA and accounts without a configured secret have no POST credential check in the controller. Production ingress controls for these cases are unconfirmed.
- **Admin and diagnostics.** `/panel` has no public registration and requires an active administrator. Horizon and Log Viewer use separate administrator checks. Existing sessions and access after account deactivation are part of this boundary.
- **Attachment download.** The web download route requires a valid Laravel signed URL. A provider that holds the URL can use it without a Hub session. Requests with an authenticated browser session also require an active administrator.
- **Health and runner control.** `/health/live` is unauthenticated. `/health/ready` requires an API credential with `integration:read`. The Hub’s `/internal/engine/desired-sessions` route and the runner HTTP API require `WAG_BAILEYS_TOKEN`. The v2 Compose example keeps the runner and MySQL on the Compose network and binds the Hub to loopback by default. The deployment guide uses an HTTPS reverse proxy and requires provider reachability for signed attachments.
- **Outbound calls and storage.** Client webhook destinations go through URL and address checks with an explicit private-host allowlist in [engine configuration](config/engine_v2.php). Sensitive Laravel model fields such as message bodies and provider configuration use encrypted casts. The v2 Compose runner uses encrypted SQL authentication state with `WAG_BAILEYS_AUTH_KEY`.

## Assets and potential impact

- **WhatsApp session authentication state and pairing material.** Exposure can allow another party to take over or impersonate a linked number.
- **API, provider, webhook and runner credentials, plus encryption keys.** Compromise can grant access to protected APIs, provider accounts or encrypted data.
- **Message content, OTPs, attachments and phone numbers.** Disclosure exposes private communications and personal data. Include message ledgers, inbox data, job payloads, logs and backups when tracing exposure.
- **Message and session integrity.** Unauthorized sends, fabricated inbound events or unauthorized session changes can misuse linked numbers and disrupt client applications.

## Attacker profiles

- An unauthenticated party who can reach routes exposed by the deployment.
- A compromised or malicious client application with a valid credential, attempting to access another application’s resources or exceed its own abilities.
- An external WhatsApp contact sending untrusted content that the Hub processes, stores or displays to operators.
- A compromised provider or a caller impersonating one and submitting false events.
- An operator with a compromised browser session, or a former operator whose session is still valid.

## Accepted risks and open questions

This baseline comes from the source and deployment examples. No deployment-specific exclusions, accepted risks or relative asset priorities have been confirmed.

- Which API, webhook and administration routes are reachable from the internet in production? Is there separate infrastructure configuration that sets those boundaries?
- Who receives client credentials, and are all active administrators equally trusted?
- Where is WABA webhook authenticity verified in production, and which providers are required to have a per-account secret?
- Which assets need the highest priority when reachability and impact are otherwise comparable?
