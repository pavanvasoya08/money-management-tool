<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Support\Money;

/**
 * Accounting integration foundation (Phase 3 → Phase 12).
 *
 * Financial modules call enqueue() inside their posting transaction. The
 * unique key (source_type, source_id, event) means the same business event
 * can be recorded only once, so it can never be counted twice in accounting.
 * No ledgers or bank accounts are invented: account references are
 * descriptive strings (e.g. "partner_capital:CAP-PTR-0001",
 * "payment_source:bank_transfer:HDFC current a/c") that Phase 5/12 will map.
 */
final class PostingQueue
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $sourceType, int $sourceId, string $event, string $entryDate, int $amountPaise, string $debitRef, string $creditRef, array $payload = []): int
    {
        if ($amountPaise <= 0) {
            throw new DomainRuleException('Posting amount must be positive.');
        }
        if ($this->db->scalar('SELECT 1 FROM accounting_posting_queue WHERE source_type = ? AND source_id = ? AND event = ?', [$sourceType, $sourceId, $event])) {
            throw new DomainRuleException('This transaction has already been posted.');
        }
        return $this->db->insert('accounting_posting_queue', [
            'source_type' => $sourceType, 'source_id' => $sourceId, 'event' => $event, 'entry_date' => $entryDate,
            'amount' => Money::toDb($amountPaise), 'debit_ref' => mb_substr($debitRef, 0, 150), 'credit_ref' => mb_substr($creditRef, 0, 150),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'pending', 'created_at' => now(),
        ]);
    }

    /** Hand pending events to an accounting engine (Phase 12). Returns the number processed. */
    public function process(PostingTarget $target, int $limit = 100): int
    {
        $done = 0;
        foreach ($this->db->select("SELECT * FROM accounting_posting_queue WHERE status = 'pending' ORDER BY id LIMIT " . max(1, $limit)) as $row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
            $journalId = $target->post($row);
            if ($journalId !== null) {
                $done += $this->db->execute("UPDATE accounting_posting_queue SET status = 'processed', journal_entry_id = ?, processed_at = ? WHERE id = ? AND status = 'pending'", [$journalId, now(), $row['id']]);
            }
        }
        return $done;
    }

    /** @return list<array<string, mixed>> */
    public function forSource(string $sourceType, int $sourceId): array
    {
        return $this->db->select('SELECT * FROM accounting_posting_queue WHERE source_type = ? AND source_id = ? ORDER BY id', [$sourceType, $sourceId]);
    }
}
