<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiny, safe formatter for Help & User Guide text. Authors write plain text;
 * every character is escaped first, then a few structures are recognised:
 *
 *   blank line         → new paragraph
 *   "- item"           → bullet list
 *   "1. item"          → numbered list
 *   "| a | b |" rows   → table (first row = header; a "|---|" row is ignored)
 *   "> text"           → highlighted note
 *   **bold**, `code`   → inline emphasis
 *
 * No HTML from authors is ever passed through, so guide content cannot inject
 * markup or scripts.
 */
final class DocText
{
    public static function html(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
        if ($text === '') {
            return '';
        }
        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), static fn (string $l): bool => $l !== ''));
            if ($lines === []) {
                continue;
            }
            $out[] = self::block($lines);
        }
        return implode("\n", $out);
    }

    /** Plain text for search snippets and meta descriptions. */
    public static function plain(?string $text): string
    {
        $t = (string) preg_replace(['/^\s*(?:[-*]|\d+\.|>)\s+/m', '/\*\*(.+?)\*\*/', '/`(.+?)`/', '/^\|?\s*-{3,}.*$/m', '/\|/'], ['', '$1', '$1', '', ' '], (string) $text);
        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /** @param list<string> $lines */
    private static function block(array $lines): string
    {
        if (self::all($lines, '/^\s*[-*]\s+/')) {
            return '<ul>' . implode('', array_map(static fn (string $l): string => '<li>' . self::inline((string) preg_replace('/^\s*[-*]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        }
        if (self::all($lines, '/^\s*\d+\.\s+/')) {
            return '<ol>' . implode('', array_map(static fn (string $l): string => '<li>' . self::inline((string) preg_replace('/^\s*\d+\.\s+/', '', $l)) . '</li>', $lines)) . '</ol>';
        }
        if (self::all($lines, '/^\s*\|.*\|\s*$/')) {
            return self::table($lines);
        }
        if (self::all($lines, '/^\s*>\s?/')) {
            $body = implode(' ', array_map(static fn (string $l): string => (string) preg_replace('/^\s*>\s?/', '', $l), $lines));
            return '<div class="doc-callout">' . self::inline($body) . '</div>';
        }
        return '<p>' . implode('<br>', array_map([self::class, 'inline'], $lines)) . '</p>';
    }

    /** @param list<string> $lines */
    private static function table(array $lines): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if (preg_match('/^[\s:|-]+$/', $line) === 1) {
                continue; // separator row
            }
            $rows[] = $cells;
        }
        if ($rows === []) {
            return '';
        }
        $head = array_shift($rows);
        $html = '<div class="table-responsive"><table class="table doc-table"><thead><tr>';
        foreach ($head as $c) {
            $html .= '<th>' . self::inline($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>';
            foreach ($r as $c) {
                $html .= '<td>' . self::inline($c) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function inline(string $text): string
    {
        $s = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $s = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        return (string) preg_replace('/`(.+?)`/u', '<code>$1</code>', $s);
    }

    /** @param list<string> $lines */
    private static function all(array $lines, string $pattern): bool
    {
        foreach ($lines as $l) {
            if (preg_match($pattern, $l) !== 1) {
                return false;
            }
        }
        return true;
    }
}
