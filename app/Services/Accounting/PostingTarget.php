<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * Contract the Phase 12 accounting engine implements to turn queued business
 * events into journal entries. Return the journal entry id, or null to leave
 * the event pending. Implementations must be idempotent per queue row.
 */
interface PostingTarget
{
    /** @param array<string, mixed> $event a row of accounting_posting_queue (payload decoded) */
    public function post(array $event): ?int;
}
