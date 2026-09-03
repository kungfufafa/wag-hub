<?php

namespace App\Filament\Support;

use Illuminate\Support\Js;

final class CopiesToClipboard
{
    public static function alpine(
        string $text,
        string $success = 'Isi pesan disalin. Tempel di WhatsApp.',
        string $failure = 'Gagal menyalin. Blok teks lalu tekan Ctrl+C atau Cmd+C.',
    ): string {
        $payload = Js::from($text);
        $okMessage = Js::from($success);
        $failMessage = Js::from($failure);

        return <<<JS
            (() => {
                const text = {$payload}

                const notify = (message) => {
                    try {
                        \$tooltip(message, {
                            theme: \$store.theme,
                            timeout: 2500,
                        })
                    } catch (e) {}
                }

                if (! text) {
                    notify({$failMessage})
                    return
                }

                const copyWithEvent = () => {
                    let copied = false
                    const onCopy = (event) => {
                        if (! event.clipboardData) {
                            return
                        }

                        event.clipboardData.setData('text/plain', text)
                        event.preventDefault()
                        copied = true
                    }

                    document.addEventListener('copy', onCopy, true)

                    try {
                        document.execCommand('copy')
                    } finally {
                        document.removeEventListener('copy', onCopy, true)
                    }

                    return copied
                }

                const copyWithTextarea = (root) => {
                    if (! root) {
                        return false
                    }

                    const el = document.createElement('textarea')
                    el.value = text
                    el.setAttribute('readonly', '')
                    el.style.cssText = 'position:fixed;top:0;left:-9999px;font-size:12pt;'
                    root.appendChild(el)
                    el.focus()
                    el.select()
                    el.setSelectionRange(0, el.value.length)

                    const focused = document.activeElement === el
                        && el.selectionEnd === el.value.length

                    let copied = false

                    try {
                        copied = focused && Boolean(document.execCommand('copy'))
                    } finally {
                        el.remove()
                    }

                    return copied
                }

                const selectVisible = () => {
                    const visible = document.querySelector('[data-message-body]')

                    if (! visible) {
                        return false
                    }

                    visible.focus()
                    visible.select()
                    visible.setSelectionRange(0, visible.value.length)

                    return true
                }

                const root = (typeof \$el !== 'undefined')
                    ? (\$el.closest('.fi-modal-window') || \$el.closest('.fi-modal') || \$el.closest('[role=dialog]') || document.body)
                    : document.body

                if (copyWithEvent() || copyWithTextarea(root)) {
                    notify({$okMessage})
                    return
                }

                if (window.navigator.clipboard?.writeText) {
                    window.navigator.clipboard.writeText(text).then(() => {
                        notify({$okMessage})
                    }).catch(() => {
                        selectVisible()
                        notify({$failMessage})
                    })
                    return
                }

                selectVisible()
                notify({$failMessage})
            })()
            JS;
    }
}
