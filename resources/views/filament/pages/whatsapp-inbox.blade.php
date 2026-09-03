@php
    $channels = $this->channels();
    $account = $this->selectedAccount();
@endphp

<style>
    .fi-sc:has(.wa-inbox),
    .fi-section:has(.wa-inbox),
    .fi-grid:has(.wa-inbox) {
        padding: 0;
        gap: 0;
        max-width: none;
    }

    .wa-inbox {
        --wa-line: color-mix(in oklab, var(--gray-400) 28%, transparent);
        --wa-muted: var(--gray-500);
        --wa-panel: var(--gray-50);
        box-sizing: border-box;
        display: grid;
        grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
        width: 100%;
        min-height: min(72vh, 46rem);
        max-height: calc(100vh - 11.5rem);
        overflow: hidden;
        border: 1px solid var(--wa-line);
        border-radius: 1rem;
        background: var(--gray-50);
        color: inherit;
        font-size: 0.9rem;
        line-height: 1.4;
    }

    .wa-inbox *,
    .wa-inbox *::before,
    .wa-inbox *::after {
        box-sizing: border-box;
    }

    .wa-inbox p,
    .wa-inbox h1,
    .wa-inbox h2,
    .wa-inbox h3 {
        margin: 0;
    }

    .wa-inbox button {
        appearance: none;
        font: inherit;
        color: inherit;
        box-shadow: none;
    }

    .dark .wa-inbox {
        --wa-line: color-mix(in oklab, var(--gray-400) 22%, transparent);
        --wa-panel: color-mix(in oklab, var(--gray-950) 55%, transparent);
        background: color-mix(in oklab, var(--gray-950) 35%, transparent);
    }

    .wa-inbox-col {
        display: flex;
        min-width: 0;
        min-height: 0;
        flex-direction: column;
        background: white;
    }

    .dark .wa-inbox-col {
        background: color-mix(in oklab, var(--gray-900) 88%, transparent);
    }

    .wa-inbox-col + .wa-inbox-col {
        border-left: 1px solid var(--wa-line);
    }

    .wa-inbox-head {
        padding: 0.85rem 1rem 0.75rem;
        border-bottom: 1px solid var(--wa-line);
    }

    .wa-inbox-kicker {
        margin: 0 0 0.5rem;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--wa-muted);
    }

    .wa-inbox-scroll {
        flex: 1;
        min-height: 0;
        overflow: auto;
        padding: 0.4rem;
    }

    .wa-inbox-item {
        display: block;
        width: 100%;
        margin: 0;
        border: 0;
        border-radius: 0.75rem;
        padding: 0.65rem 0.75rem;
        background: transparent;
        text-align: left;
        cursor: pointer;
    }

    .wa-inbox-item:hover {
        background: var(--wa-panel);
    }

    .wa-inbox-item.is-active {
        background: color-mix(in oklab, var(--primary-500) 12%, transparent);
    }

    .wa-inbox-item-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.9rem;
        font-weight: 650;
    }

    .wa-inbox-meta {
        margin: 0.15rem 0 0;
        overflow: hidden;
        color: var(--wa-muted);
        font-size: 0.75rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .wa-inbox-badge {
        flex: none;
        border-radius: 999px;
        padding: 0.12rem 0.45rem;
        background: var(--wa-panel);
        color: var(--wa-muted);
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .wa-inbox-thread {
        background: color-mix(in oklab, var(--primary-500) 4%, var(--gray-50));
    }

    .dark .wa-inbox-thread {
        background: color-mix(in oklab, var(--gray-950) 45%, transparent);
    }

    .wa-inbox-thread-body {
        display: flex;
        flex: 1;
        min-height: 0;
        flex-direction: column;
        gap: 0.45rem;
        overflow: auto;
        padding: 0.9rem;
    }

    .wa-bubble-row {
        display: flex;
        width: 100%;
    }

    .wa-bubble-row.is-in {
        justify-content: flex-start;
    }

    .wa-bubble-row.is-out {
        justify-content: flex-end;
    }

    .wa-bubble {
        display: flex;
        max-width: min(28rem, 78%);
        flex-direction: column;
        gap: 0.2rem;
        border-radius: 1.05rem;
        padding: 0.5rem 0.7rem 0.4rem;
        font-size: 0.9rem;
        line-height: 1.4;
    }

    .wa-bubble.is-in {
        background: white;
        box-shadow: 0 1px 2px color-mix(in oklab, var(--gray-950) 8%, transparent);
    }

    .dark .wa-bubble.is-in {
        background: color-mix(in oklab, var(--gray-800) 90%, transparent);
    }

    .wa-bubble.is-out {
        background: var(--primary-600);
        color: white;
    }

    .wa-bubble-text {
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .wa-bubble-time {
        margin: 0;
        font-size: 0.65rem;
        line-height: 1;
        opacity: 0.7;
        text-align: right;
    }

    .wa-inbox-composer {
        display: grid;
        gap: 0.5rem;
        padding: 0.75rem;
        border-top: 1px solid var(--wa-line);
        background: white;
    }

    .dark .wa-inbox-composer {
        background: color-mix(in oklab, var(--gray-900) 88%, transparent);
    }

    .wa-inbox-composer-row {
        display: flex;
        gap: 0.5rem;
        align-items: flex-end;
    }

    .wa-inbox input,
    .wa-inbox textarea,
    .wa-inbox select {
        width: 100%;
        margin: 0;
        border: 1px solid var(--wa-line);
        border-radius: 0.75rem;
        padding: 0.6rem 0.75rem;
        background: var(--wa-panel);
        color: inherit;
        font: inherit;
        line-height: 1.4;
        outline: none;
        box-shadow: none;
    }

    .wa-inbox textarea {
        min-height: 2.6rem;
        max-height: 8rem;
        resize: vertical;
    }

    .wa-inbox-send {
        flex: none;
        height: 2.6rem;
        margin: 0;
        border: 0;
        border-radius: 0.75rem;
        padding: 0 1rem;
        background: var(--primary-600);
        color: white;
        font-weight: 650;
        cursor: pointer;
    }

    .wa-inbox-empty {
        margin: 0;
        color: var(--wa-muted);
        font-size: 0.9rem;
    }

    @media (max-width: 1023px) {
        .wa-inbox {
            grid-template-columns: 1fr;
            max-height: none;
        }

        .wa-inbox-col + .wa-inbox-col {
            border-left: 0;
            border-top: 1px solid var(--wa-line);
        }

        .wa-inbox-scroll {
            max-height: 16rem;
        }

        .wa-inbox-thread-body {
            min-height: 18rem;
        }
    }
</style>

<div class="wa-inbox" wire:poll.8s="refreshInbox">
    <section class="wa-inbox-col">
        <div class="wa-inbox-head">
            <p class="wa-inbox-kicker">Percakapan</p>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama, nomor, atau akun">
        </div>
        <div class="wa-inbox-scroll">
            @forelse ($this->chats as $chat)
                <button
                    type="button"
                    wire:click="selectChat(@js($chat['id']), @js($chat['title']), {{ (int) ($chat['provider_id'] ?? 0) }})"
                    class="wa-inbox-item {{ $this->isActiveChat($chat) ? 'is-active' : '' }}"
                >
                    <span class="wa-inbox-item-title">
                        <span>{{ $chat['title'] }}</span>
                        <span class="wa-inbox-badge">{{ $this->driverLabel($chat['driver'] ?? null) }}</span>
                    </span>
                    <p class="wa-inbox-meta">
                        {{ $chat['is_group'] ? 'Grup · ' : '' }}{{ ($chat['last_from_me'] ?? false) ? 'Anda: ' : '' }}{{ $chat['preview'] !== '' ? $chat['preview'] : $chat['id'] }}
                    </p>
                    <p class="wa-inbox-meta">
                        {{ $chat['provider_name'] ?? '' }}{{ filled($chat['timestamp']) ? ' · '.$chat['timestamp'] : '' }}
                    </p>
                </button>
            @empty
                <p class="wa-inbox-empty">Belum ada percakapan. Mulai obrolan baru, atau tunggu pesan masuk.</p>
            @endforelse
        </div>
    </section>

    <section class="wa-inbox-col wa-inbox-thread">
        <div class="wa-inbox-head">
            <p class="wa-inbox-item-title">
                <span>{{ $this->chatTitle !== '' ? $this->chatTitle : 'Pilih percakapan' }}</span>
                @if ($account && filled($this->chatId))
                    <span class="wa-inbox-badge">{{ $this->driverLabel($account->driver) }}</span>
                @endif
            </p>
            @if ($account && filled($this->chatId))
                <p class="wa-inbox-meta">Lewat {{ $account->name }}</p>
            @endif
            @if ($this->status)
                <p class="wa-inbox-meta">{{ $this->status }}</p>
            @endif
        </div>

        <div class="wa-inbox-thread-body" x-data x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
            @if ($this->composingNew)
                <p class="wa-inbox-empty">Pilih akun wrapping, isi nomor tujuan, lalu kirim. Balasan masuk di sisi kiri.</p>
            @elseif ($this->messages === [] && filled($this->chatId))
                <p class="wa-inbox-empty">Belum ada pesan di percakapan ini.</p>
            @elseif ($this->chatId === null && ! $this->composingNew)
                <p class="wa-inbox-empty">Pilih percakapan di kiri, atau klik Obrolan baru.</p>
            @endif

            @foreach ($this->messages as $message)
                <div class="wa-bubble-row {{ $message['from_me'] ? 'is-out' : 'is-in' }}" wire:key="msg-{{ $message['id'] }}">
                    <div class="wa-bubble {{ $message['from_me'] ? 'is-out' : 'is-in' }}">
                        <div class="wa-bubble-text">{{ $message['body'] !== '' ? $message['body'] : '[Tanpa teks]' }}</div>
                        @if (filled($message['timestamp']))
                            <div class="wa-bubble-time">{{ $message['timestamp'] }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <form wire:submit="send" class="wa-inbox-composer">
            @if ($this->composingNew)
                @if ($channels->count() > 1)
                    <select wire:model="providerId">
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->id }}">
                                {{ $this->driverLabel($channel->driver) }} · {{ $channel->name }}{{ $channel->is_active ? '' : ' (off)' }}
                            </option>
                        @endforeach
                    </select>
                @endif
                <input type="text" wire:model="newRecipient" placeholder="Nomor tujuan, contoh 081234567890">
            @endif
            <div class="wa-inbox-composer-row">
                <textarea wire:model="draft" rows="2" placeholder="Tulis pesan..."></textarea>
                <button type="submit" class="wa-inbox-send">Kirim</button>
            </div>
        </form>
    </section>
</div>
