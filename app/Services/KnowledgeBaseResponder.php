<?php

namespace App\Services;

use App\Infrastructure\Ai\OpenAiChatProvider;
use App\Models\KnowledgeBaseEntry;
use App\Models\ProviderAccount;
use Illuminate\Support\Collection;

/**
 * The AI agent (cekat.ai-style): answers a free-text question from the
 * provider's knowledge base. Retrieval is deterministic and works without any
 * external service; when an LLM is configured it phrases the answer naturally
 * using the retrieved entries as grounding context.
 */
final readonly class KnowledgeBaseResponder
{
    /** Common Indonesian/English stopwords ignored during retrieval. */
    private const STOPWORDS = [
        'yang', 'dan', 'atau', 'dengan', 'untuk', 'pada', 'dari', 'kah', 'dong',
        'saya', 'aku', 'kami', 'kita', 'anda', 'kamu', 'mau', 'ingin', 'tolong',
        'apa', 'apakah', 'berapa', 'kapan', 'dimana', 'gimana', 'bagaimana',
        'min', 'kak', 'bang', 'halo', 'hai', 'permisi', 'the', 'and', 'for',
        'you', 'your', 'are', 'can', 'how', 'what', 'when', 'where', 'is', 'it',
    ];

    public function __construct(
        private OpenAiChatProvider $llm,
    ) {}

    /**
     * Answer the question from the knowledge base, or null when nothing relevant
     * is found (so the caller can stay silent or hand off).
     */
    public function answer(ProviderAccount $account, string $question): ?string
    {
        if (! (bool) config('gateway.ai.enabled', true)) {
            return null;
        }

        $entries = $account->knowledgeBaseEntries()->where('is_active', true)->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $tokens = $this->tokenize($question);

        if ($tokens === []) {
            return null;
        }

        $ranked = $this->rank($entries, $tokens);

        if ($ranked->isEmpty()) {
            return null;
        }

        $best = $ranked->first();

        if ($best['score'] < (int) config('gateway.ai.min_score', 1)) {
            return null;
        }

        // Ground an LLM answer on the top entries when available; otherwise
        // return the best entry verbatim.
        $context = $ranked->take(3)
            ->map(fn (array $row): string => '- '.$row['entry']->title.': '.$row['entry']->content)
            ->implode("\n");

        $llmAnswer = $this->llm->answer(
            "Anda adalah asisten customer service yang ramah dan ringkas. Jawab HANYA berdasarkan informasi berikut; jika tidak ada, katakan Anda akan menghubungkan ke agen. Gunakan bahasa yang sama dengan pelanggan.\n\nInformasi:\n".$context,
            $question,
        );

        return $llmAnswer ?? $best['entry']->content;
    }

    /**
     * @param  Collection<int, KnowledgeBaseEntry>  $entries
     * @param  list<string>  $tokens
     * @return Collection<int, array{entry: KnowledgeBaseEntry, score: int}>
     */
    private function rank(Collection $entries, array $tokens): Collection
    {
        return $entries
            ->map(function (KnowledgeBaseEntry $entry) use ($tokens): array {
                $haystack = $entry->searchableText();
                $score = 0;

                foreach ($tokens as $token) {
                    $score += mb_substr_count($haystack, $token) > 0 ? 1 : 0;
                }

                return ['entry' => $entry, 'score' => $score];
            })
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($text))) ?: [];

        $tokens = array_filter($parts, static fn (string $token): bool => mb_strlen($token) >= 3
            && ! in_array($token, self::STOPWORDS, true));

        return array_values(array_unique($tokens));
    }
}
