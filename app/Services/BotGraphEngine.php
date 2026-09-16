<?php

namespace App\Services;

use App\Domain\Inbox\InboxEvent;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Models\BotConversationState;
use App\Models\BotGraph;
use App\Models\ProviderAccount;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Executes visual (n8n-style) bot graphs built in the Flow Builder.
 *
 * Node types: trigger, message, condition (2 outputs), ai (knowledge base),
 * menu (self-contained interactive wait), handoff. It walks connected nodes on
 * each inbound message, pausing at menu nodes (stored in conversation state).
 */
final readonly class BotGraphEngine
{
    private const MENU_WORDS = ['menu', '0', 'kembali', 'back'];
    private const MAX_STEPS = 25;

    public function __construct(
        private WhatsAppInbox $inbox,
        private KnowledgeBaseResponder $knowledge,
    ) {}

    /**
     * Resume a graph that is paused at a menu node.
     */
    public function continueActive(ProviderAccount $account, InboxEvent $event): bool
    {
        $chatKey = InboxPayload::peerKey($event->chatId);
        $state = BotConversationState::query()
            ->where('provider_account_id', $account->id)
            ->where('chat_key', $chatKey)
            ->whereNotNull('bot_graph_id')
            ->first();

        if ($state === null) {
            return false;
        }

        if ($state->isExpired()) {
            $state->delete();

            return false;
        }

        $graph = BotGraph::query()->find($state->bot_graph_id);
        $nodes = $graph?->nodes() ?? [];
        $node = $nodes[$state->node_id] ?? null;

        if ($graph === null || ! $graph->is_active || $node === null || $node['type'] !== 'menu') {
            $state->delete();

            return false;
        }

        $body = trim($event->body);
        $option = $this->matchMenuOption($node, $body);

        if ($option !== null) {
            if ($option['action'] === 'handoff') {
                $this->send($account, $event, $graph, 'opt-'.$option['key'],
                    $option['reply'] ?: 'Baik, Anda akan dibantu agen kami sebentar lagi.');
                $state->delete();

                return true;
            }

            $this->send($account, $event, $graph, 'opt-'.$option['key'],
                ($option['reply'] ?: 'Baik.')."\n\n_Ketik *menu* untuk kembali ke pilihan._");
            $this->touch($state);

            return true;
        }

        if (in_array(mb_strtolower($body), self::MENU_WORDS, true)) {
            $this->send($account, $event, $graph, 'menu', $this->renderMenu($node));
            $this->touch($state);

            return true;
        }

        $fallback = InboxPayload::string($node['data']['fallback'] ?? null) ?? 'Maaf, pilihan tidak dikenali.';
        $this->send($account, $event, $graph, 'fallback', $fallback."\n\n".$this->renderMenu($node));
        $this->touch($state);

        return true;
    }

    /**
     * Start a graph whose trigger matches this (first) message.
     */
    public function startIfTriggered(ProviderAccount $account, InboxEvent $event, bool $isFirstContact): bool
    {
        $body = trim($event->body);

        $graphs = $account->botGraphs()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($graphs as $graph) {
            $triggerId = $graph->triggerNodeId();

            if ($triggerId === null) {
                continue;
            }

            $trigger = $graph->nodes()[$triggerId];

            if (! $this->triggerMatches($trigger, $body, $isFirstContact)) {
                continue;
            }

            $this->walk($account, $event, $graph, $graph->nodes(),
                $this->next($trigger, 'output_1'));

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, array{type: string, data: array<string, mixed>, outputs: array<string, string>}>  $nodes
     */
    private function walk(ProviderAccount $account, InboxEvent $event, BotGraph $graph, array $nodes, ?string $nodeId): void
    {
        $body = trim($event->body);
        $steps = 0;

        while ($nodeId !== null && isset($nodes[$nodeId]) && $steps++ < self::MAX_STEPS) {
            $node = $nodes[$nodeId];

            switch ($node['type']) {
                case 'message':
                    $this->send($account, $event, $graph, 'n-'.$nodeId,
                        (string) ($node['data']['text'] ?? ''));
                    $nodeId = $this->next($node, 'output_1');
                    break;

                case 'condition':
                    $matched = $this->keywordMatch($body, (string) ($node['data']['keywords'] ?? ''));
                    $nodeId = $this->next($node, $matched ? 'output_1' : 'output_2');
                    break;

                case 'ai':
                    $answer = $this->knowledge->answer($account, $body);

                    if ($answer !== null) {
                        $this->send($account, $event, $graph, 'ai-'.$nodeId, $answer);
                    }

                    $nodeId = $this->next($node, 'output_1');
                    break;

                case 'menu':
                    $this->send($account, $event, $graph, 'n-'.$nodeId, $this->renderMenu($node));
                    $this->pauseAt($account, $event, $graph, $nodeId);

                    return;

                case 'handoff':
                    $this->send($account, $event, $graph, 'n-'.$nodeId,
                        (string) ($node['data']['message'] ?? 'Baik, Anda akan dibantu agen kami.'));
                    $this->clear($account, $event);

                    return;

                default:
                    $nodeId = $this->next($node, 'output_1');
            }
        }

        $this->clear($account, $event);
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, outputs: array<string, string>}  $trigger
     */
    private function triggerMatches(array $trigger, string $body, bool $isFirstContact): bool
    {
        if (($trigger['data']['trigger_type'] ?? 'keyword') === 'welcome') {
            return $isFirstContact;
        }

        return $this->keywordMatch($body, (string) ($trigger['data']['keywords'] ?? ''));
    }

    private function keywordMatch(string $body, string $keywordCsv): bool
    {
        $haystack = mb_strtolower(trim($body));

        foreach ($this->csv($keywordCsv) as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, outputs: array<string, string>}  $node
     * @return array{key: string, label: string, action: string, reply: ?string}|null
     */
    private function matchMenuOption(array $node, string $input): ?array
    {
        $needle = mb_strtolower(trim($input));

        foreach ($this->menuOptions($node) as $option) {
            if ($needle === mb_strtolower($option['key']) || $needle === mb_strtolower($option['label'])) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Menu options come as lines "key|label|action|reply".
     *
     * @param  array{type: string, data: array<string, mixed>, outputs: array<string, string>}  $node
     * @return list<array{key: string, label: string, action: string, reply: ?string}>
     */
    private function menuOptions(array $node): array
    {
        $options = [];

        foreach (preg_split('/\r?\n/', (string) ($node['data']['options'] ?? '')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));

            if (($parts[0] ?? '') === '' || ($parts[1] ?? '') === '') {
                continue;
            }

            $options[] = [
                'key' => $parts[0],
                'label' => $parts[1],
                'action' => ($parts[2] ?? 'reply') === 'handoff' ? 'handoff' : 'reply',
                'reply' => $parts[3] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, outputs: array<string, string>}  $node
     */
    private function renderMenu(array $node): string
    {
        $lines = [trim((string) ($node['data']['header'] ?? 'Silakan pilih:')), ''];

        foreach ($this->menuOptions($node) as $option) {
            $lines[] = '*'.$option['key'].'.* '.$option['label'];
        }

        if (filled($node['data']['footer'] ?? null)) {
            $lines[] = '';
            $lines[] = trim((string) $node['data']['footer']);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, outputs: array<string, string>}  $node
     */
    private function next(array $node, string $output): ?string
    {
        return $node['outputs'][$output] ?? null;
    }

    /**
     * @return list<string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $part): string => mb_strtolower(trim($part)),
            explode(',', $value),
        ), static fn (string $part): bool => $part !== ''));
    }

    private function pauseAt(ProviderAccount $account, InboxEvent $event, BotGraph $graph, string $nodeId): void
    {
        BotConversationState::query()->updateOrCreate(
            ['provider_account_id' => $account->id, 'chat_key' => InboxPayload::peerKey($event->chatId)],
            ['bot_graph_id' => $graph->id, 'bot_flow_id' => null, 'node_id' => $nodeId, 'expires_at' => now()->addMinutes(10)],
        );
    }

    private function touch(BotConversationState $state): void
    {
        $state->forceFill(['expires_at' => now()->addMinutes(10)])->save();
    }

    private function clear(ProviderAccount $account, InboxEvent $event): void
    {
        BotConversationState::query()
            ->where('provider_account_id', $account->id)
            ->where('chat_key', InboxPayload::peerKey($event->chatId))
            ->whereNotNull('bot_graph_id')
            ->delete();
    }

    private function send(ProviderAccount $account, InboxEvent $event, BotGraph $graph, string $step, string $body): void
    {
        if (trim($body) === '') {
            return;
        }

        $submissionUuid = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'botgraph:'.$account->id.':'.$graph->id.':'.$event->messageId.':'.$step,
        )->toString();

        try {
            $this->inbox->send($account, $event->chatId, $body, null, $submissionUuid);
        } catch (Throwable) {
            // Never let automation break inbound webhook processing.
        }
    }
}
