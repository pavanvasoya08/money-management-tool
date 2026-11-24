<?php

declare(strict_types=1);

namespace App\Services\Documentation;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\DocText;

/**
 * Help & User Guide content.
 *
 * Reading: published guides are returned to every signed-in user; there is no
 * module, role, record or field check on reading (by design: the manual is for
 * everyone). Drafts are only returned when the caller passes $withDrafts, which
 * controllers do only for holders of documentation.manage.
 *
 * Writing: callers must already have passed the Gate (documentation.manage /
 * .publish / .delete). Every change writes a revision snapshot and an audit entry.
 */
final class DocumentationService
{
    public const TEXT_FIELDS = ['what_is', 'why_used', 'who_uses', 'prerequisites', 'example_body', 'expected_result', 'important_notes', 'common_mistakes'];

    public function __construct(private readonly Database $db)
    {
    }

    // ------------------------------------------------------------------ reading

    /** @return list<array<string, mixed>> categories with published (and optionally draft) guide counts */
    public function categories(bool $withDrafts = false): array
    {
        $rows = $this->db->select(
            "SELECT c.*, (SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = c.id AND g.status = 'published') AS published_count,
                    (SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = c.id) AS guide_count
             FROM documentation_categories c ORDER BY c.sort_order, c.id"
        );
        return array_values(array_filter($rows, static fn (array $c): bool => $withDrafts || (int) $c['published_count'] > 0));
    }

    /** @return array<string, mixed>|null */
    public function category(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM documentation_categories WHERE slug = ?', [$slug]);
    }

    /** @return array<string, mixed>|null */
    public function categoryById(int $id): ?array
    {
        return $this->db->first('SELECT * FROM documentation_categories WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function guidesIn(int $categoryId, bool $withDrafts = false): array
    {
        return $this->db->select(
            'SELECT g.*, (SELECT COUNT(*) FROM documentation_steps s WHERE s.guide_id = g.id) AS step_count
             FROM documentation_guides g WHERE g.category_id = ?' . ($withDrafts ? '' : " AND g.status = 'published'") . ' ORDER BY g.sort_order, g.id',
            [$categoryId]
        );
    }

    /** @return array<string, mixed>|null */
    public function guide(string $slug, bool $withDrafts = false): ?array
    {
        return $this->db->first(
            'SELECT g.*, c.slug AS category_slug, c.name AS category_name FROM documentation_guides g JOIN documentation_categories c ON c.id = g.category_id
             WHERE g.slug = ?' . ($withDrafts ? '' : " AND g.status = 'published'"),
            [$slug]
        );
    }

    /** @return array<string, mixed>|null */
    public function guideById(int $id): ?array
    {
        return $this->db->first(
            'SELECT g.*, c.slug AS category_slug, c.name AS category_name FROM documentation_guides g JOIN documentation_categories c ON c.id = g.category_id WHERE g.id = ?',
            [$id]
        );
    }

    /**
     * Everything needed to render one guide. Related guides and prev/next only
     * point at published guides unless $withDrafts.
     *
     * @return array{guide: array<string, mixed>, steps: list<array<string, mixed>>, media: list<array<string, mixed>>, faqs: list<array<string, mixed>>, related: list<array<string, mixed>>}
     */
    public function assemble(array $guide, bool $withDrafts = false): array
    {
        $id = (int) $guide['id'];
        $media = array_map([self::class, 'decodeMedia'], $this->db->select('SELECT * FROM documentation_media WHERE guide_id = ? ORDER BY sort_order, id', [$id]));
        return [
            'guide' => $guide,
            'steps' => $this->db->select('SELECT * FROM documentation_steps WHERE guide_id = ? ORDER BY sort_order, id', [$id]),
            'media' => $media,
            'faqs' => $this->db->select('SELECT * FROM documentation_faqs WHERE guide_id = ? ORDER BY sort_order, id', [$id]),
            'related' => $this->db->select(
                'SELECT g.id, g.slug, g.title, g.summary, g.status, c.name AS category_name FROM documentation_related_guides r
                 JOIN documentation_guides g ON g.id = r.related_guide_id JOIN documentation_categories c ON c.id = g.category_id
                 WHERE r.guide_id = ?' . ($withDrafts ? '' : " AND g.status = 'published'") . ' ORDER BY r.sort_order, r.id',
                [$id]
            ),
        ];
    }

    /** @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>} reading order across the whole manual */
    public function neighbours(int $guideId, bool $withDrafts = false): array
    {
        $order = $this->db->select(
            'SELECT g.id, g.slug, g.title FROM documentation_guides g JOIN documentation_categories c ON c.id = g.category_id'
            . ($withDrafts ? '' : " WHERE g.status = 'published'") . ' ORDER BY c.sort_order, c.id, g.sort_order, g.id'
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $order);
        $pos = array_search($guideId, $ids, true);
        if ($pos === false) {
            return ['prev' => null, 'next' => null];
        }
        return ['prev' => $order[$pos - 1] ?? null, 'next' => $order[$pos + 1] ?? null];
    }

    /** @return list<array<string, mixed>> */
    public function popular(int $limit = 6): array
    {
        return $this->published('g.view_count DESC, g.is_featured DESC, c.sort_order, g.sort_order', $limit);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 6): array
    {
        return $this->published('g.updated_at DESC, g.id DESC', $limit);
    }

    /** @return list<array<string, mixed>> */
    public function tutorials(int $limit = 8): array
    {
        return self::interleave($this->published('c.sort_order, g.sort_order', 50, 'g.is_featured = 1'), $limit);
    }

    /** @return list<array<string, mixed>> guides that contain a practical example */
    public function examples(int $limit = 6): array
    {
        return self::interleave($this->published('c.sort_order, g.sort_order', 50, "g.example_title IS NOT NULL AND g.example_title <> ''"), $limit);
    }

    /** @return list<array<string, mixed>> */
    public function generalFaqs(): array
    {
        return $this->db->select('SELECT * FROM documentation_faqs WHERE guide_id IS NULL ORDER BY sort_order, id');
    }

    /**
     * Search published guides across titles, categories, keywords, introductions,
     * every text section, steps and FAQs. Ranked: title > keywords > summary > body.
     *
     * @return list<array<string, mixed>> guides with 'score' and 'snippet'
     */
    public function search(string $query, int $limit = 30, bool $withDrafts = false): array
    {
        $terms = array_values(array_unique(array_filter(preg_split('/\s+/', mb_strtolower(trim($query))) ?: [], static fn (string $t): bool => mb_strlen($t) >= 2)));
        if ($terms === []) {
            return [];
        }
        $terms = array_slice($terms, 0, 8);
        $text = "LOWER(COALESCE(g.title,'') || ' ' || COALESCE(g.keywords,'') || ' ' || COALESCE(g.summary,'') || ' ' || COALESCE(g.section,'') || ' ' || c.name || ' ' || COALESCE(g.what_is,'') || ' ' || COALESCE(g.why_used,'') || ' ' || COALESCE(g.who_uses,'') || ' ' || COALESCE(g.prerequisites,'') || ' ' || COALESCE(g.example_title,'') || ' ' || COALESCE(g.example_body,'') || ' ' || COALESCE(g.expected_result,'') || ' ' || COALESCE(g.important_notes,'') || ' ' || COALESCE(g.common_mistakes,''))";
        if ($this->db->driver() === 'mysql') {
            $text = "LOWER(CONCAT_WS(' ', g.title, g.keywords, g.summary, g.section, c.name, g.what_is, g.why_used, g.who_uses, g.prerequisites, g.example_title, g.example_body, g.expected_result, g.important_notes, g.common_mistakes))";
        }
        $where = [];
        $params = [];
        foreach ($terms as $t) {
            $like = Database::likeContains($t);
            $where[] = "({$text} LIKE ? ESCAPE '!'
                OR EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id AND LOWER(s.title || ' ' || COALESCE(s.body,'')) LIKE ? ESCAPE '!')
                OR EXISTS (SELECT 1 FROM documentation_faqs f WHERE f.guide_id = g.id AND LOWER(f.question || ' ' || f.answer) LIKE ? ESCAPE '!'))";
            array_push($params, $like, $like, $like);
        }
        $sql = "SELECT g.*, c.name AS category_name, c.slug AS category_slug FROM documentation_guides g JOIN documentation_categories c ON c.id = g.category_id
                WHERE " . implode(' AND ', $where) . ($withDrafts ? '' : " AND g.status = 'published'");
        if ($this->db->driver() === 'mysql') {
            $sql = str_replace(["LOWER(s.title || ' ' || COALESCE(s.body,''))", "LOWER(f.question || ' ' || f.answer)"], ["LOWER(CONCAT_WS(' ', s.title, s.body))", "LOWER(CONCAT_WS(' ', f.question, f.answer))"], $sql);
        }
        $rows = $this->db->select($sql, $params);

        foreach ($rows as &$r) {
            $score = 0;
            foreach ($terms as $t) {
                $score += (str_contains(mb_strtolower((string) $r['title']), $t) ? 10 : 0)
                    + (str_contains(mb_strtolower((string) $r['keywords']), $t) ? 6 : 0)
                    + (str_contains(mb_strtolower((string) $r['category_name'] . ' ' . $r['section']), $t) ? 3 : 0)
                    + (str_contains(mb_strtolower((string) $r['summary']), $t) ? 4 : 0) + 1;
            }
            $r['score'] = $score;
            $r['snippet'] = $this->snippet($r, $terms);
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);
        return array_slice($rows, 0, $limit);
    }

    /** Count a view once per guide per browser session (used for "Popular guides"). */
    public function recordView(int $guideId): void
    {
        $session = App::session();
        $seen = (array) $session->get('help_seen', []);
        if (in_array($guideId, $seen, true)) {
            return;
        }
        $seen[] = $guideId;
        $session->put('help_seen', array_slice($seen, -200));
        $this->db->execute('UPDATE documentation_guides SET view_count = view_count + 1 WHERE id = ?', [$guideId]);
    }

    // ------------------------------------------------------------------ writing

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveCategory(array $data, array $actor, ?int $id = null): int
    {
        $row = [
            'name' => trim((string) $data['name']),
            'slug' => $this->uniqueSlug('documentation_categories', (string) (($data['slug'] ?? '') !== '' ? $data['slug'] : $data['name']), $id),
            'description' => self::nullable($data['description'] ?? null),
            'icon' => self::nullable(preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($data['icon'] ?? '')))),
            'updated_by' => (int) $actor['id'],
            'updated_at' => now(),
        ];
        if ($id === null) {
            $row['sort_order'] = 10 + (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM documentation_categories');
            $id = $this->db->insert('documentation_categories', $row + ['created_by' => (int) $actor['id'], 'created_at' => now()]);
            App::activity()->log('documentation.category_created', "Created help category \"{$row['name']}\"", 'documentation_category', $id, module: 'documentation', newValues: $row);
            return $id;
        }
        $before = $this->categoryById($id) ?? throw new DomainRuleException('Category not found.');
        $this->db->update('documentation_categories', $row, ['id' => $id]);
        App::activity()->log('documentation.category_updated', "Updated help category \"{$row['name']}\"", 'documentation_category', $id, module: 'documentation',
            oldValues: array_intersect_key($before, $row), newValues: $row);
        return $id;
    }

    /** @param array<string, mixed> $actor */
    public function deleteCategory(int $id, array $actor): void
    {
        $cat = $this->categoryById($id) ?? throw new DomainRuleException('Category not found.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE category_id = ?', [$id]) > 0) {
            throw new DomainRuleException('Move or delete the guides in this category first.');
        }
        $this->db->delete('documentation_categories', ['id' => $id]);
        App::activity()->log('documentation.category_deleted', "Deleted help category \"{$cat['name']}\"", 'documentation_category', $id, module: 'documentation', oldValues: $cat);
    }

    /**
     * Create or update a guide with its steps, FAQs and related guides. Status is
     * never changed here (see setStatus) except that new guides start as drafts.
     *
     * @param array<string, mixed> $data validated guide fields plus 'steps', 'faqs', 'related' arrays
     * @param array<string, mixed> $actor
     */
    public function saveGuide(array $data, array $actor, ?int $id = null, string $note = ''): int
    {
        if ($this->categoryById((int) $data['category_id']) === null) {
            throw ValidationException::withMessage('category_id', 'Choose a category.');
        }
        $row = [
            'category_id' => (int) $data['category_id'],
            'title' => trim((string) $data['title']),
            'slug' => $this->uniqueSlug('documentation_guides', (string) (($data['slug'] ?? '') !== '' ? $data['slug'] : $data['title']), $id),
            'section' => self::nullable($data['section'] ?? null),
            'summary' => trim((string) $data['summary']),
            'example_title' => self::nullable($data['example_title'] ?? null),
            'keywords' => self::nullable($data['keywords'] ?? null),
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'updated_by' => (int) $actor['id'],
            'updated_at' => now(),
        ];
        foreach (self::TEXT_FIELDS as $f) {
            $row[$f] = self::nullable($data[$f] ?? null);
        }
        $steps = self::cleanRows((array) ($data['steps'] ?? []), ['title', 'body', 'media_id'], 'title');
        $faqs = self::cleanRows((array) ($data['faqs'] ?? []), ['question', 'answer'], 'question');
        foreach ($faqs as $i => $f) {
            if (trim((string) $f['answer']) === '') {
                throw ValidationException::withMessage('faqs', 'FAQ ' . ($i + 1) . ' needs an answer.');
            }
        }
        $related = array_values(array_unique(array_filter(array_map('intval', (array) ($data['related'] ?? [])), static fn (int $r): bool => $r > 0 && $r !== $id)));

        return $this->db->transaction(function () use ($row, $steps, $faqs, $related, $actor, $id, $note): int {
            if ($id === null) {
                $row['sort_order'] = 10 + (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM documentation_guides WHERE category_id = ?', [$row['category_id']]);
                $id = $this->db->insert('documentation_guides', $row + ['status' => 'draft', 'view_count' => 0, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
                $event = 'created';
            } else {
                if ($this->guideById($id) === null) {
                    throw new DomainRuleException('Guide not found.');
                }
                $this->db->update('documentation_guides', $row, ['id' => $id]);
                $event = 'updated';
            }
            $mediaIds = array_map('intval', array_column($this->db->select('SELECT id FROM documentation_media WHERE guide_id = ?', [$id]), 'id'));
            $this->db->delete('documentation_steps', ['guide_id' => $id]);
            foreach ($steps as $i => $s) {
                $media = (int) ($s['media_id'] ?? 0);
                $this->db->insert('documentation_steps', [
                    'guide_id' => $id, 'sort_order' => ($i + 1) * 10, 'title' => mb_substr(trim((string) $s['title']), 0, 200),
                    'body' => self::nullable($s['body'] ?? null), 'media_id' => in_array($media, $mediaIds, true) ? $media : null,
                ]);
            }
            $this->db->delete('documentation_faqs', ['guide_id' => $id]);
            foreach ($faqs as $i => $f) {
                $this->db->insert('documentation_faqs', ['guide_id' => $id, 'sort_order' => ($i + 1) * 10, 'question' => mb_substr(trim((string) $f['question']), 0, 300), 'answer' => trim((string) $f['answer'])]);
            }
            $this->db->delete('documentation_related_guides', ['guide_id' => $id]);
            foreach ($related as $i => $rid) {
                if ($this->db->scalar('SELECT 1 FROM documentation_guides WHERE id = ?', [$rid])) {
                    $this->db->insert('documentation_related_guides', ['guide_id' => $id, 'related_guide_id' => $rid, 'sort_order' => ($i + 1) * 10]);
                }
            }
            $rev = $this->writeRevision($id, $event, $note, $actor);
            App::activity()->log('documentation.guide_' . $event, ucfirst($event) . " help guide \"{$row['title']}\" (revision {$rev})", 'documentation_guide', $id, module: 'documentation',
                newValues: ['title' => $row['title'], 'category_id' => $row['category_id'], 'steps' => count($steps), 'faqs' => count($faqs), 'revision' => $rev]);
            return $id;
        });
    }

    /** @param array<string, mixed> $actor */
    public function setStatus(int $id, bool $publish, array $actor): void
    {
        $g = $this->guideById($id) ?? throw new DomainRuleException('Guide not found.');
        $status = $publish ? 'published' : 'draft';
        if ($g['status'] === $status) {
            return;
        }
        if ($publish && (int) $this->db->scalar('SELECT COUNT(*) FROM documentation_steps WHERE guide_id = ?', [$id]) === 0 && trim((string) $g['what_is']) === '') {
            throw new DomainRuleException('Add at least an explanation or one step before publishing.');
        }
        $this->db->update('documentation_guides', ['status' => $status, 'published_at' => $publish ? now() : $g['published_at'], 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
        $rev = $this->writeRevision($id, $publish ? 'published' : 'unpublished', '', $actor);
        App::activity()->log('documentation.guide_' . ($publish ? 'published' : 'unpublished'), ($publish ? 'Published' : 'Unpublished') . " help guide \"{$g['title']}\"", 'documentation_guide', $id,
            module: 'documentation', oldValues: ['status' => $g['status']], newValues: ['status' => $status, 'revision' => $rev]);
    }

    /** @param array<string, mixed> $actor */
    public function deleteGuide(int $id, array $actor, DocumentationMediaService $media): void
    {
        $g = $this->guideById($id) ?? throw new DomainRuleException('Guide not found.');
        $files = $this->db->select('SELECT * FROM documentation_media WHERE guide_id = ?', [$id]);
        $this->db->transaction(function () use ($id): void {
            $this->db->delete('documentation_related_guides', ['related_guide_id' => $id]);
            $this->db->delete('documentation_guides', ['id' => $id]); // steps, media rows, FAQs, related, revisions cascade
        });
        foreach ($files as $f) {
            $media->removeFile($f);
        }
        App::activity()->log('documentation.guide_deleted', "Deleted help guide \"{$g['title']}\"", 'documentation_guide', $id, module: 'documentation',
            oldValues: ['title' => $g['title'], 'slug' => $g['slug'], 'status' => $g['status'], 'category_id' => (int) $g['category_id']]);
    }

    /**
     * Reorder categories or the guides of one category. Unknown ids are ignored.
     * @param list<int> $ids in the desired order
     * @param array<string, mixed> $actor
     */
    public function reorder(string $what, array $ids, array $actor, ?int $categoryId = null): void
    {
        $table = $what === 'categories' ? 'documentation_categories' : 'documentation_guides';
        $this->db->transaction(function () use ($table, $ids, $categoryId): void {
            foreach (array_values($ids) as $i => $id) {
                $where = ['id' => (int) $id];
                if ($table === 'documentation_guides') {
                    $where['category_id'] = (int) $categoryId;
                }
                $this->db->update($table, ['sort_order' => ($i + 1) * 10], $where);
            }
        });
        App::activity()->log('documentation.reordered', 'Reordered help ' . ($what === 'categories' ? 'categories' : 'guides'), $what === 'categories' ? 'documentation_category' : 'documentation_category',
            $categoryId, module: 'documentation', newValues: ['order' => array_map('intval', $ids)], userId: (int) $actor['id']);
    }

    /** @return list<array<string, mixed>> */
    public function revisions(int $guideId): array
    {
        return $this->db->select(
            'SELECT r.id, r.revision_no, r.event, r.change_note, r.created_at, u.name AS author FROM documentation_revisions r LEFT JOIN users u ON u.id = r.created_by
             WHERE r.guide_id = ? ORDER BY r.revision_no DESC',
            [$guideId]
        );
    }

    /** @return array<string, mixed>|null decoded revision snapshot */
    public function revision(int $guideId, int $revisionId): ?array
    {
        $r = $this->db->first('SELECT * FROM documentation_revisions WHERE id = ? AND guide_id = ?', [$revisionId, $guideId]);
        if ($r === null) {
            return null;
        }
        $r['snapshot'] = json_decode((string) $r['snapshot'], true) ?: [];
        return $r;
    }

    /** @param array<string, mixed> $actor */
    public function writeRevision(int $guideId, string $event, string $note, ?array $actor): int
    {
        $g = $this->guideById($guideId) ?? throw new DomainRuleException('Guide not found.');
        $snap = $this->assemble($g, true);
        unset($snap['guide']['view_count']);
        $no = 1 + (int) $this->db->scalar('SELECT COALESCE(MAX(revision_no), 0) FROM documentation_revisions WHERE guide_id = ?', [$guideId]);
        $this->db->insert('documentation_revisions', [
            'guide_id' => $guideId, 'revision_no' => $no, 'event' => $event, 'change_note' => self::nullable(mb_substr($note, 0, 300)),
            'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_by' => $actor !== null ? (int) $actor['id'] : null, 'created_at' => now(),
        ]);
        return $no;
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array<string, mixed>> */
    private function published(string $order, int $limit, string $extra = '1 = 1'): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT g.id, g.slug, g.title, g.summary, g.section, g.example_title, g.updated_at, g.view_count, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
             FROM documentation_guides g JOIN documentation_categories c ON c.id = g.category_id
             WHERE g.status = 'published' AND {$extra} ORDER BY {$order} LIMIT {$limit}"
        );
    }

    /**
     * Take guides from each category in turn so home-page lists cover every topic.
     * @param list<array<string, mixed>> $rows already in reading order
     * @return list<array<string, mixed>>
     */
    private static function interleave(array $rows, int $limit): array
    {
        $byCat = [];
        foreach ($rows as $r) {
            $byCat[(string) $r['category_slug']][] = $r;
        }
        $out = [];
        while (count($out) < $limit && $byCat !== []) {
            foreach ($byCat as $k => &$list) {
                $out[] = array_shift($list);
                if ($list === []) {
                    unset($byCat[$k]);
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
            unset($list);
        }
        return $out;
    }

    /** @param list<string> $terms */
    private function snippet(array $guide, array $terms): string
    {
        $sources = [(string) $guide['summary']];
        foreach (self::TEXT_FIELDS as $f) {
            $sources[] = DocText::plain((string) ($guide[$f] ?? ''));
        }
        foreach ($sources as $src) {
            $low = mb_strtolower($src);
            foreach ($terms as $t) {
                $pos = mb_strpos($low, $t);
                if ($pos !== false) {
                    $start = max(0, $pos - 60);
                    return ($start > 0 ? '… ' : '') . mb_substr($src, $start, 200) . (mb_strlen($src) > $start + 200 ? ' …' : '');
                }
            }
        }
        return mb_substr((string) $guide['summary'], 0, 200);
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        return mb_substr($slug !== '' ? $slug : 'guide', 0, 110);
    }

    private function uniqueSlug(string $table, string $source, ?int $ignoreId): string
    {
        $base = self::slugify($source);
        $slug = $base;
        $n = 2;
        while ($this->db->scalar("SELECT id FROM {$table} WHERE slug = ?" . ($ignoreId !== null ? ' AND id <> ?' : ''), $ignoreId !== null ? [$slug, $ignoreId] : [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /**
     * Keep rows whose $required key is filled, limited to known keys.
     * @param array<int|string, mixed> $rows
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private static function cleanRows(array $rows, array $keys, string $required): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || trim((string) ($r[$required] ?? '')) === '') {
                continue;
            }
            $out[] = array_intersect_key($r, array_flip($keys));
        }
        return array_slice($out, 0, 60);
    }

    /** @param array<string, mixed> $m */
    public static function decodeMedia(array $m): array
    {
        $m['annotations'] = DocumentationMediaService::decodeAnnotations((string) ($m['annotations'] ?? ''));
        return $m;
    }

    private static function nullable(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : $v;
    }
}
