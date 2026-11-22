<?php

namespace App\Support;

/**
 * Sépare le texte d'une réponse de la citation du message précédent
 * (« Le … a écrit : », « On … wrote: », lignes « > », en-têtes Outlook).
 */
final class QuotedText
{
    public static function strip(string $text): string
    {
        $lines = preg_split('/\R/u', str_replace("\r\n", "\n", $text)) ?: [];
        $kept = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            // Gmail coupe parfois « … a » / « écrit : » sur deux lignes.
            $withNext = $trimmed.' '.trim($lines[$i + 1] ?? '');

            if (str_starts_with($trimmed, '>')
                || preg_match('/^(Le|On)\s.+(a\s+écrit|wrote)\s*:\s*$/iu', $trimmed)
                || preg_match('/^(Le|On)\s.+(a\s+écrit|wrote)\s*:\s*$/iu', $withNext)
                || preg_match('/^-{2,}\s*(Original Message|Message d\'origine|Message original)/iu', $trimmed)
                || preg_match('/^_{8,}$/', $trimmed)
                || preg_match('/^(De|From)\s?:\s.+@.+/iu', $trimmed) && $i > 0 && trim($lines[$i - 1]) === '') {
                break;
            }

            $kept[] = $line;
        }

        $result = trim(implode("\n", $kept));

        return $result === '' ? trim($text) : $result;
    }

    /** Convertit un corps HTML en texte lisible (repli quand le message n'a pas de partie texte). */
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<blockquote[^>]*>#i', "\n> ", $html) ?? $html;
        $text = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
