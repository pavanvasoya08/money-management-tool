<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\App;
use App\Core\Seeder;
use App\Services\Documentation\DocumentationMediaService;
use App\Services\Documentation\DocumentationService;

/**
 * Seeds the Help & User Guide for Phases 1–3 from the text files in
 * database/seeders/documentation/<category>/NN-<slug>.txt and the screenshots
 * in resources/help/screenshots (manifest.json holds captions and markers).
 *
 * Idempotent and respectful of edits: a category or guide whose slug already
 * exists is never touched, so changes made by documentation managers survive
 * every `db:seed`. Seeded guides are published.
 *
 * Guide file format (plain text, formatted by App\Support\DocText):
 *   key: value header lines (title, slug, section, summary, keywords, related, featured)
 *   === what | why | who | prerequisites | result | notes | mistakes
 *   === step: Step title | screenshot-name     (screenshot optional)
 *   === shots: name, name                      (screenshots not tied to a step)
 *   === example: Example title
 *   === faq: Question
 */
final class DocumentationSeeder extends Seeder
{
    public const CATEGORIES = [
        '1-setup-authentication' => ['setup-authentication', 'Setup & Authentication', 'Phase 1 — getting started, signing in, passwords, sessions, users, roles and the activity log.', 'log-in'],
        '2-super-admin-control' => ['super-admin-control', 'Super Admin Control System', 'Phase 2 — the Control Center: roles, permissions, overrides, record and field access, workflows, preview, sessions and audit.', 'shield-check'],
        '3-partners-capital' => ['partners-capital', 'Partner Management & Capital', 'Phase 3 — partners, documents, logins, capital contributions, withdrawals, adjustments, the ledger, profit sharing and reports.', 'users-round'],
        '4-gst-registrations' => ['gst-registrations', 'Phase 4 – GST Registration Management', 'Phase 4 — GST registrations, GSTIN validation, partner and activity mappings, locations, documents, status and reports.', 'file-badge'],
        '5-bank-accounts' => ['bank-accounts', 'Phase 5 – Bank Account Management', 'Phase 5 — bank accounts, the central funding account, deposits, withdrawals, internal transfers, statements, CSV import, reconciliation, reports and permissions.', 'landmark'],
    ];
    private const FIELD_MAP = ['what' => 'what_is', 'why' => 'why_used', 'who' => 'who_uses', 'prerequisites' => 'prerequisites', 'result' => 'expected_result', 'notes' => 'important_notes', 'mistakes' => 'common_mistakes'];

    public function run(): void
    {
        $dir = App::basePath('database/seeders/documentation');
        if (!is_dir($dir)) {
            return;
        }
        $manifestFile = DocumentationMediaService::bundledDirectory() . '/manifest.json';
        $manifest = is_file($manifestFile) ? (array) json_decode((string) file_get_contents($manifestFile), true) : [];
        $docs = new DocumentationService($this->db);

        $this->db->transaction(function () use ($dir, $manifest, $docs): void {
            $now = now();
            $order = 0;
            $created = []; // slug => [id, related slugs]
            foreach (self::CATEGORIES as $folder => [$slug, $name, $description, $icon]) {
                $order += 10;
                $catId = $this->db->scalar('SELECT id FROM documentation_categories WHERE slug = ?', [$slug]);
                if ($catId === null) {
                    $catId = $this->db->insert('documentation_categories', ['slug' => $slug, 'name' => $name, 'description' => $description, 'icon' => $icon, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
                }
                $files = glob($dir . '/' . $folder . '/*.txt') ?: [];
                sort($files);
                foreach ($files as $i => $file) {
                    $g = self::parse((string) file_get_contents($file));
                    if ($g['slug'] === '' || $this->db->scalar('SELECT 1 FROM documentation_guides WHERE slug = ?', [$g['slug']])) {
                        continue; // never overwrite an existing (possibly edited) guide
                    }
                    $id = $this->insertGuide((int) $catId, $g, ($i + 1) * 10, $manifest, $now);
                    $created[$g['slug']] = [$id, $g['related']];
                }
            }
            foreach ($created as [$id, $related]) {
                foreach ($related as $n => $rslug) {
                    $rid = $this->db->scalar('SELECT id FROM documentation_guides WHERE slug = ?', [$rslug]);
                    if ($rid !== null && (int) $rid !== $id) {
                        $this->db->insert('documentation_related_guides', ['guide_id' => $id, 'related_guide_id' => (int) $rid, 'sort_order' => ($n + 1) * 10]);
                    }
                }
                $docs->writeRevision($id, 'seeded', 'Initial Phase 1–3 user guide', null);
            }
            if (!$this->db->scalar('SELECT 1 FROM documentation_faqs WHERE guide_id IS NULL LIMIT 1') && is_file($dir . '/general-faqs.txt')) {
                foreach (self::parse((string) file_get_contents($dir . '/general-faqs.txt'))['faqs'] as $n => [$q, $a]) {
                    $this->db->insert('documentation_faqs', ['guide_id' => null, 'question' => $q, 'answer' => $a, 'sort_order' => ($n + 1) * 10]);
                }
            }
        });
    }

    /** @param array<string, mixed> $g @param array<string, mixed> $manifest */
    private function insertGuide(int $catId, array $g, int $sort, array $manifest, string $now): int
    {
        $row = [
            'category_id' => $catId, 'slug' => $g['slug'], 'title' => $g['title'], 'section' => $g['section'] ?: null, 'summary' => $g['summary'],
            'example_title' => $g['example_title'], 'keywords' => $g['keywords'] ?: null, 'sort_order' => $sort, 'status' => 'published',
            'is_featured' => $g['featured'] ? 1 : 0, 'view_count' => 0, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (DocumentationService::TEXT_FIELDS as $f) {
            $row[$f] = null;
        }
        foreach (self::FIELD_MAP as $key => $column) {
            $row[$column] = $g['sections'][$key] ?? null;
        }
        $row['example_body'] = $g['example_body'];
        $id = $this->db->insert('documentation_guides', $row);

        $mediaIds = [];
        $mediaFor = function (string $name) use (&$mediaIds, $id, $manifest, $now): ?int {
            if (isset($mediaIds[$name])) {
                return $mediaIds[$name];
            }
            $path = DocumentationMediaService::bundledDirectory() . '/' . $name . '.webp';
            if (!is_file($path)) {
                return null;
            }
            $meta = (array) ($manifest[$name] ?? []);
            $markers = DocumentationMediaService::cleanAnnotations((array) ($meta['markers'] ?? []));
            return $mediaIds[$name] = $this->db->insert('documentation_media', [
                'guide_id' => $id, 'stored_name' => 'bundled:' . $name . '.webp', 'original_name' => $name . '.webp', 'mime_type' => 'image/webp',
                'size_bytes' => (int) filesize($path), 'width' => isset($meta['width']) ? (int) $meta['width'] : null, 'height' => isset($meta['height']) ? (int) $meta['height'] : null,
                'caption' => $meta['caption'] ?? null, 'alt_text' => 'Screenshot: ' . rtrim((string) ($meta['caption'] ?? $name), '.'),
                'annotations' => $markers === [] ? null : json_encode($markers, JSON_UNESCAPED_UNICODE),
                'sort_order' => (count($mediaIds) + 1) * 10, 'uploaded_by' => null, 'created_at' => $now,
            ]);
        };
        foreach ($g['steps'] as $n => [$title, $body, $shot]) {
            $this->db->insert('documentation_steps', [
                'guide_id' => $id, 'sort_order' => ($n + 1) * 10, 'title' => $title, 'body' => $body !== '' ? $body : null,
                'media_id' => $shot !== null ? $mediaFor($shot) : null,
            ]);
        }
        foreach ($g['shots'] as $shot) {
            $mediaFor($shot);
        }
        foreach ($g['faqs'] as $n => [$q, $a]) {
            $this->db->insert('documentation_faqs', ['guide_id' => $id, 'question' => $q, 'answer' => $a, 'sort_order' => ($n + 1) * 10]);
        }
        return $id;
    }

    /**
     * Parse one guide file.
     * @return array{slug: string, title: string, section: string, summary: string, keywords: string, featured: bool, related: list<string>,
     *   sections: array<string, string>, steps: list<array{0: string, 1: string, 2: ?string}>, shots: list<string>, faqs: list<array{0: string, 1: string}>,
     *   example_title: ?string, example_body: ?string}
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/^=== /m', $text) ?: [];
        $head = (string) array_shift($parts);
        $meta = [];
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $m) === 1) {
                $meta[$m[1]] = trim($m[2]);
            }
        }
        $out = [
            'slug' => (string) ($meta['slug'] ?? ''), 'title' => (string) ($meta['title'] ?? ''), 'section' => (string) ($meta['section'] ?? ''),
            'summary' => (string) ($meta['summary'] ?? ''), 'keywords' => (string) ($meta['keywords'] ?? ''), 'featured' => ($meta['featured'] ?? '') === 'yes',
            'related' => array_values(array_filter(array_map('trim', explode(',', (string) ($meta['related'] ?? ''))))),
            'sections' => [], 'steps' => [], 'shots' => [], 'faqs' => [], 'example_title' => null, 'example_body' => null,
        ];
        foreach ($parts as $part) {
            [$label, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $label = trim($label);
            $body = trim($body);
            if (str_starts_with($label, 'step:')) {
                [$title, $shot] = array_pad(array_map('trim', explode('|', substr($label, 5), 2)), 2, null);
                $out['steps'][] = [(string) $title, $body, $shot !== '' ? $shot : null];
            } elseif (str_starts_with($label, 'shots:')) {
                $out['shots'] = array_merge($out['shots'], array_values(array_filter(array_map('trim', explode(',', substr($label, 6))))));
            } elseif (str_starts_with($label, 'faq:')) {
                $out['faqs'][] = [trim(substr($label, 4)), $body];
            } elseif (str_starts_with($label, 'example:')) {
                $out['example_title'] = trim(substr($label, 8));
                $out['example_body'] = $body;
            } else {
                $out['sections'][$label] = $body;
            }
        }
        return $out;
    }
}
