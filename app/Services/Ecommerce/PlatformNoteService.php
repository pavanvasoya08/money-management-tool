<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/**
 * Internal notes on a platform with visibility:
 * - everyone: anyone who can open the platform;
 * - managers: users with platforms.manage_notes;
 * - private: the author and Super Admins.
 * Notes are edited by their author (or a Super Admin) and archived, never deleted.
 */
final class PlatformNoteService
{
    public const VISIBILITY = ['everyone' => 'Everyone who can see the platform', 'managers' => 'Note managers only', 'private' => 'Only me (and Super Admins)'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $user @return list<array<string, mixed>> */
    public function visible(int $platformId, array $user, bool $withArchived = false): array
    {
        $rows = $this->db->select('SELECT n.*, cu.name AS created_by_name, uu.name AS updated_by_name FROM ecommerce_platform_notes n LEFT JOIN users cu ON cu.id = n.created_by
            LEFT JOIN users uu ON uu.id = n.updated_by WHERE n.platform_id = ?' . ($withArchived ? '' : " AND n.status = 'active'") . ' ORDER BY n.id DESC', [$platformId]);
        return array_values(array_filter($rows, fn (array $n): bool => $this->canSee($n, $user)));
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $user */
    public function canSee(array $n, array $user): bool
    {
        $gate = App::gate();
        return match ($n['visibility']) {
            'everyone' => true,
            'managers' => $gate->isSuperAdmin($user) || $gate->allows($user, 'platforms.manage_notes') || (int) $n['created_by'] === (int) $user['id'],
            default => $gate->isSuperAdmin($user) || (int) $n['created_by'] === (int) $user['id'],
        };
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $user */
    public function canEdit(array $n, array $user): bool
    {
        return $n['status'] === 'active' && (App::gate()->isSuperAdmin($user) || (int) $n['created_by'] === (int) $user['id']);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function save(array $p, array $data, ?array $note, array $actor): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('This platform is archived and read-only.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $vis = (string) ($data['visibility'] ?? 'everyone');
        if ($title === '' || mb_strlen($title) > 150) {
            throw ValidationException::withMessage('title', 'Enter a title of up to 150 characters.');
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            throw ValidationException::withMessage('body', 'Enter the note (up to 10,000 characters).');
        }
        if (!isset(self::VISIBILITY[$vis])) {
            throw ValidationException::withMessage('visibility', 'Choose who can see the note.');
        }
        if ($note === null) {
            $id = $this->db->insert('ecommerce_platform_notes', ['platform_id' => (int) $p['id'], 'title' => $title, 'body' => $body, 'visibility' => $vis, 'status' => 'active',
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            $event = 'note_added';
        } else {
            if (!$this->canEdit($note, $actor)) {
                throw new DomainRuleException('Only the author can change this note.');
            }
            $id = (int) $note['id'];
            $this->db->update('ecommerce_platform_notes', ['title' => $title, 'body' => $body, 'visibility' => $vis, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
            $event = 'note_changed';
        }
        // History keeps the title and visibility only, so a private note's text is not exposed through the history tab.
        PlatformHistory::write($this->db, (int) $p['id'], $event, null, ['note_id' => $id, 'title' => $vis === 'everyone' ? $title : '(restricted note)', 'visibility' => $vis], null, $actor);
        App::activity()->log('platforms.' . $event, ($note === null ? 'Added' : 'Changed') . " a note on platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms',
            newValues: ['note_id' => $id, 'visibility' => $vis]);
        return $id;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $note @param array<string, mixed> $actor */
    public function archive(array $p, array $note, array $actor): void
    {
        if (!$this->canEdit($note, $actor)) {
            throw new DomainRuleException('Only the author can archive this note.');
        }
        $this->db->update('ecommerce_platform_notes', ['status' => 'archived', 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $note['id']]);
        PlatformHistory::write($this->db, (int) $p['id'], 'note_archived', null, ['note_id' => (int) $note['id']], null, $actor);
        App::activity()->log('platforms.note_archived', "Archived a note on platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms', newValues: ['note_id' => (int) $note['id']]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM ecommerce_platform_notes WHERE id = ?', [$id]);
    }
}
