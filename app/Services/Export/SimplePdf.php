<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * Minimal PDF 1.4 writer for tabular reports: A4 landscape, Helvetica
 * (WinAnsi), automatic column widths, page breaks with repeated headers and
 * "Page X of Y" footers. Text outside Windows-1252 is transliterated
 * (₹ → "Rs.", others → "?"). Enough for printable reports; no dependencies.
 */
final class SimplePdf
{
    private const W = 842.0;
    private const H = 595.0;
    private const M = 32.0;
    private const FS = 7.5;
    private const ROW = 13.0;

    /** Helvetica glyph widths (1/1000 em) for common characters; others default to 556. */
    private const WIDTHS = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 278, ']' => 278, '_' => 556, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500,
        't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, "\x95" => 350, "\x96" => 556, "\x97" => 1000,
    ];

    /**
     * @param list<string> $meta @param list<string> $headers @param list<list<string>> $rows
     * @param list<string> $align 'L'|'R' per column @param list<string>|null $totals
     */
    public function table(string $title, array $meta, array $headers, array $rows, array $align, ?array $totals = null): string
    {
        $headers = array_map([$this, 'enc'], $headers);
        $rows = array_map(fn (array $r): array => array_map([$this, 'enc'], $r), $rows);
        $totals = $totals !== null ? array_map([$this, 'enc'], $totals) : null;
        $widths = $this->columnWidths($headers, array_merge($rows, $totals !== null ? [$totals] : []));

        $pages = [];
        $ops = '';
        $y = 0.0;
        $newPage = function () use (&$ops, &$y, &$pages, $title, $meta, $headers, $widths): void {
            if ($ops !== '') {
                $pages[] = $ops;
            }
            $ops = '';
            $y = self::H - self::M;
            if ($pages === []) {
                $ops .= $this->text(self::M, $y - 12, $this->enc($title), 14, true);
                $y -= 22;
                foreach ($meta as $m) {
                    $ops .= $this->text(self::M, $y - 8, $this->enc($m), 8, false, '0.35 0.4 0.47');
                    $y -= 11;
                }
                $y -= 6;
            }
            $ops .= sprintf("0.93 0.96 1 rg %.2F %.2F %.2F %.2F re f\n", self::M, $y - self::ROW, self::W - 2 * self::M, self::ROW);
            $x = self::M;
            foreach ($headers as $i => $h) {
                $ops .= $this->text($x + 3, $y - 9.2, $this->fit($h, $widths[$i] - 6, 7.5), 7.5, true);
                $x += $widths[$i];
            }
            $y -= self::ROW;
        };
        $newPage();
        $line = function (array $cells, bool $bold, bool $shade) use (&$ops, &$y, $widths, $align, $newPage): void {
            if ($y - self::ROW < self::M + 16) {
                $newPage();
            }
            if ($shade) {
                $ops .= sprintf("0.97 0.98 0.99 rg %.2F %.2F %.2F %.2F re f\n", self::M, $y - self::ROW, self::W - 2 * self::M, self::ROW);
            }
            $x = self::M;
            foreach ($cells as $i => $c) {
                $c = $this->fit($c, $widths[$i] - 6, self::FS);
                $tx = $align[$i] === 'R' ? $x + $widths[$i] - 3 - $this->width($c, self::FS) : $x + 3;
                $ops .= $this->text($tx, $y - 9.2, $c, self::FS, $bold);
                $x += $widths[$i];
            }
            $ops .= sprintf("0.89 0.91 0.94 RG 0.4 w %.2F %.2F m %.2F %.2F l S\n", self::M, $y - self::ROW, self::W - self::M, $y - self::ROW);
            $y -= self::ROW;
        };
        if ($rows === []) {
            $ops .= $this->text(self::M + 3, $y - 12, 'No records match the selected filters.', 8, false, '0.4 0.45 0.5');
        }
        foreach ($rows as $n => $r) {
            $line($r, false, $n % 2 === 1);
        }
        if ($totals !== null) {
            $line($totals, true, false);
        }
        $pages[] = $ops;

        // Assemble objects: 1 catalog, 2 pages, 3 font regular, 4 font bold, then content/page pairs.
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $total = count($pages);
        foreach ($pages as $i => $content) {
            $content .= $this->text(self::M, 18, $this->enc('Generated ' . gmdate('Y-m-d H:i') . ' UTC'), 7, false, '0.5 0.55 0.6');
            $label = 'Page ' . ($i + 1) . ' of ' . $total;
            $content .= $this->text(self::W - self::M - $this->width($label, 7), 18, $label, 7, false, '0.5 0.55 0.6');
            $contentId = 5 + $i * 2;
            $pageId = $contentId + 1;
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $kids[] = "{$pageId} 0 R";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objects));
        $out .= 'xref' . "\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= 'trailer' . "\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    /** UTF-8 → Windows-1252 bytes, with ₹ and unsupported glyphs transliterated. */
    public function enc(string $s): string
    {
        $s = str_replace(['₹', '—', '–', '•', '…'], ['Rs.', "\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\xA2", '...'], $s);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $s) : $converted;
    }

    public function width(string $s, float $size): float
    {
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $w += self::WIDTHS[$s[$i]] ?? 556;
        }
        return $w * $size / 1000;
    }

    /** @param list<string> $headers @param list<list<string>> $rows @return list<float> */
    private function columnWidths(array $headers, array $rows): array
    {
        $need = [];
        foreach ($headers as $i => $h) {
            $need[$i] = min(140.0, max(36.0, $this->width($h, 7.5) + 8));
        }
        foreach (array_slice($rows, 0, 300) as $r) {
            foreach ($r as $i => $c) {
                $need[$i] = min(170.0, max($need[$i] ?? 36.0, $this->width($c, self::FS) + 8));
            }
        }
        $avail = self::W - 2 * self::M;
        $sum = array_sum($need) ?: 1;
        return array_map(static fn (float $w): float => $w * $avail / $sum, $need);
    }

    private function fit(string $s, float $max, float $size): string
    {
        if ($this->width($s, $size) <= $max) {
            return $s;
        }
        while ($s !== '' && $this->width($s . '...', $size) > $max) {
            $s = substr($s, 0, -1);
        }
        return $s . '...';
    }

    private function text(float $x, float $y, string $s, float $size, bool $bold = false, string $rgb = '0.06 0.09 0.16'): string
    {
        $s = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
        return sprintf("BT %s rg /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb, $bold ? 'F2' : 'F1', $size, $x, $y, $s);
    }
}
