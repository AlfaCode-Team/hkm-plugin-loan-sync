<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * The per-tenant revision counter — the single number every cursor is cut from.
 *
 * WHY A COUNTER AND NOT A TIMESTAMP
 * ---------------------------------
 * Two devices can write in the same millisecond, and a phone's clock is
 * routinely wrong by minutes. A pull that asks for "everything after
 * 12:04:03.233" either repeats rows or loses them, and losing them is silent.
 * A counter has neither failure.
 *
 * WHY THE ROW IS LOCKED
 * ---------------------
 * This is the part that makes the cursor correct, and it is not obvious.
 *
 * Suppose revs were handed out without a lock. Transaction A takes rev 5 and
 * transaction B takes rev 6, but B commits first. A device pulls, sees rev 6 as
 * the newest row, and stores cursor = 6. Transaction A then commits rev 5 — and
 * no pull will ever ask for it again, because every future request is for rows
 * ABOVE 6. The row is not late. It is gone, from that device, forever.
 *
 * Taking `SELECT ... FOR UPDATE` on the single counter row serialises the whole
 * business: the next writer cannot even learn its rev until the previous writer
 * has committed. So revs are assigned AND become visible in the same order, and
 * a reader that can see rev N can see every rev below it.
 *
 * The lock is therefore held for the length of the write transaction, which is
 * the cost of this design: pushes to one tenant serialise. For a loan book with
 * a few dozen field officers that is nothing, and the alternative is a class of
 * data loss that no amount of retrying can repair.
 *
 * HOW THE FOUR DRIVERS SPELL IT
 * -----------------------------
 * MySQL and PostgreSQL take `FOR UPDATE` as a trailing clause. SQL Server has
 * no such clause: it spells the same lock as a table hint, and `UPDLOCK` alone
 * is not enough — without `HOLDLOCK` the lock ends with the STATEMENT rather
 * than the transaction, so a second writer can read the counter the first one
 * is about to bump. SQLite needs neither, because it takes a write lock on the
 * whole database for the length of any write transaction.
 *
 * {@see SqlDialect::tableLock()} and {@see SqlDialect::rowLock()} place each
 * half where that driver wants it, and refuse rather than guess when nothing
 * can name the driver — because the failure mode of a wrong guess here is the
 * silent one described above, not a syntax error.
 */
final class RevisionRepository implements SyncRevisionContract
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /**
     * Reserve the next rev. MUST be called inside a transaction.
     *
     * @throws RepositoryException
     */
    public function next(): int
    {
        if (!$this->db->inTransaction()) {
            // Outside a transaction the lock is released the instant it is
            // taken, which quietly removes the ordering guarantee above and
            // leaves everything still looking like it works.
            throw new RepositoryException(
                'A revision may only be reserved inside a transaction.',
                layer: 'repository.sync.revision',
            );
        }

        try {
            $dialect = SqlDialect::of($this->db);

            $row = $this->db->queryOne(sprintf(
                'SELECT current_rev FROM sync_revision%s WHERE id = 1%s',
                $dialect->tableLock(),
                $dialect->rowLock(),
            ));

            if ($row === null) {
                // First write in a freshly provisioned tenant.
                $this->db->execute(
                    'INSERT INTO sync_revision (id, current_rev, updated_at) VALUES (1, 1, :now)',
                    ['now' => $this->now()],
                );

                return 1;
            }

            $next = ((int) $row['current_rev']) + 1;

            $this->db->execute(
                'UPDATE sync_revision SET current_rev = :rev, updated_at = :now WHERE id = 1',
                ['rev' => $next, 'now' => $this->now()],
            );

            return $next;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to reserve a sync revision.',
                layer: 'repository.sync.revision',
                previous: $e,
            );
        }
    }

    /** The newest rev this tenant has issued. Read-only; no lock. */
    public function current(): int
    {
        try {
            $row = $this->db->queryOne('SELECT current_rev FROM sync_revision WHERE id = 1');
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to read the sync revision.',
                layer: 'repository.sync.revision',
                previous: $e,
            );
        }

        return $row === null ? 0 : (int) $row['current_rev'];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
