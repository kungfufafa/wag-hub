<?php

namespace App\Services;

use App\Domain\Inbox\InboxEvent;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Models\AutoReplyRule;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Inbound automation engine (Phase 1): evaluates a provider's active
 * auto-reply rules against each incoming message and, on the first match,
 * sends the reply back through the normal delivery pipeline.
 *
 * Guardrails:
 *  - only reacts to inbound (fromMe = false) text;
 *  - skips group chats by default;
 *  - pauses when a human agent has recently replied (handoff);
 *  - idempotent per inbound message (safe against webhook re-delivery).
 */
final readonly class AutoResponder
{
    public function __construct(
        private WhatsAppInbox $inbox,
    ) {}

    public function handle(ProviderAccount $account, InboxEvent $event, bool $conversationExisted): void
    {
        if (! (bool) config('gateway.automation.enabled', true)) {
            return;
        }

        if ($event->fromMe) {
            return;
        }

        if ($event->isGroup && (bool) config('gateway.automation.skip_groups', true)) {
            return;
        }

        $body = trim($event->body);

        if ($body === '') {
            return;
        }

        if ($this->humanRecentlyReplied($account, $event->chatId)) {
            return;
        }

        $rule = $this->match($account, $body, ! $conversationExisted);

        if ($rule === null) {
            return;
        }

        // Derive a stable submission UUID so a re-delivered webhook (or a
        // duplicate event) never sends the same auto-reply twice.
        $submissionUuid = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'autoreply:'.$account->id.':'.$event->messageId.':'.$rule->id,
        )->toString();

        try {
            $this->inbox->send($account, $event->chatId, $rule->reply_body, null, $submissionUuid);
        } catch (Throwable) {
            // Never let automation break inbound webhook processing.
        }
    }

    private function match(ProviderAccount $account, string $body, bool $isFirstContact): ?AutoReplyRule
    {
        $rules = $account->autoReplyRules()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matches($body, $isFirstContact)) {
                return $rule;
            }
        }

        return null;
    }

    private function humanRecentlyReplied(ProviderAccount $account, string $chatId): bool
    {
        $minutes = (int) config('gateway.automation.handoff_pause_minutes', 30);

        if ($minutes <= 0) {
            return false;
        }

        $peer = InboxPayload::peerKey($chatId);

        return GatewayMessage::query()
            ->where('origin', 'inbox')
            ->where('pinned_provider_account_id', $account->id)
            ->whereNotNull('origin_user_id')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->where(function ($query) use ($chatId, $peer): void {
                $query->where('inbox_chat_id', $chatId)
                    ->orWhere('inbox_chat_id', 'like', '%'.$peer.'%');
            })
            ->exists();
    }
}
