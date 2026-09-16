<?php

namespace App\Services;

use App\Domain\Inbox\InboxEvent;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Models\BotConversationState;
use App\Models\BotFlow;
use App\Models\ProviderAccount;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Runs guided menu flows (Fonnte / cekat-style). Holds per-chat state so a
 * customer can navigate a numbered menu across several messages, with a human
 * handoff option. Replies go through the normal delivery pipeline.
 */
final readonly class BotFlowEngine
{
    /** Words that re-open the current menu. */
    private const MENU_WORDS = ['menu', '0', 'kembali', 'back'];

    public function __construct(
        private WhatsAppInbox $inbox,
    ) {}

    /**
     * If the chat is mid-flow, interpret the message as a menu choice.
     * Returns true when the message was handled by an active flow.
     */
    public function continueActive(ProviderAccount $account, InboxEvent $event): bool
    {
        $chatKey = InboxPayload::peerKey($event->chatId);
        $state = BotConversationState::query()
            ->where('provider_account_id', $account->id)
            ->where('chat_key', $chatKey)
            ->first();

        if ($state === null) {
            return false;
        }

        if ($state->isExpired() || $state->bot_flow_id === null) {
            $state->delete();

            return false;
        }

        $flow = BotFlow::query()->find($state->bot_flow_id);

        if ($flow === null || ! $flow->is_active) {
            $state->delete();

            return false;
        }

        $body = trim($event->body);
        $option = $flow->matchOption($body);

        if ($option !== null) {
            if ($option['action'] === 'handoff') {
                $this->reply($account, $event, $flow, 'opt-'.$option['key'],
                    $option['reply'] ?: 'Baik, Anda akan dibantu oleh agen kami sebentar lagi.');
                $state->delete();

                return true;
            }

            $this->reply($account, $event, $flow, 'opt-'.$option['key'],
                ($option['reply'] ?: 'Baik.')."\n\n_Ketik *menu* untuk kembali ke pilihan._");
            $this->refresh($state, $flow);

            return true;
        }

        if (in_array(mb_strtolower($body), self::MENU_WORDS, true)) {
            $this->reply($account, $event, $flow, 'menu', $flow->renderMenu());
            $this->refresh($state, $flow);

            return true;
        }

        $this->reply($account, $event, $flow, 'fallback',
            ($flow->fallback_reply ?: 'Maaf, pilihan tidak dikenali.')."\n\n".$flow->renderMenu());
        $this->refresh($state, $flow);

        return true;
    }

    /**
     * Start a flow if one is triggered by this (first) message.
     * Returns true when a flow was started.
     */
    public function startIfTriggered(ProviderAccount $account, InboxEvent $event, bool $isFirstContact): bool
    {
        $body = trim($event->body);

        $flow = $account->botFlows()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (BotFlow $flow): bool => $flow->triggeredBy($body, $isFirstContact));

        if ($flow === null) {
            return false;
        }

        $this->reply($account, $event, $flow, 'start', $flow->renderMenu());

        BotConversationState::query()->updateOrCreate(
            ['provider_account_id' => $account->id, 'chat_key' => InboxPayload::peerKey($event->chatId)],
            ['bot_flow_id' => $flow->id, 'expires_at' => now()->addMinutes(max(1, $flow->session_ttl_minutes))],
        );

        return true;
    }

    private function refresh(BotConversationState $state, BotFlow $flow): void
    {
        $state->forceFill(['expires_at' => now()->addMinutes(max(1, $flow->session_ttl_minutes))])->save();
    }

    private function reply(ProviderAccount $account, InboxEvent $event, BotFlow $flow, string $step, string $body): void
    {
        // Deterministic submission id → safe against webhook re-delivery.
        $submissionUuid = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'botflow:'.$account->id.':'.$flow->id.':'.$event->messageId.':'.$step,
        )->toString();

        try {
            $this->inbox->send($account, $event->chatId, $body, null, $submissionUuid);
        } catch (Throwable) {
            // Never let automation break inbound webhook processing.
        }
    }
}
