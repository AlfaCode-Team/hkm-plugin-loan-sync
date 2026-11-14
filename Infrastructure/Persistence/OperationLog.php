<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanSync\Domain\Operation;
use Plugins\LoanSync\Domain\OperationOutcome;
use Plugins\LoanSync\Domain\OperationStatus;

/**
 * The idempotency ledger: which op_ids this tenant has already settled, and how.
 *
 * A phone retries a push it never got an answer to, and over a rural data
 * connection that is the NORMAL case rather than an edge one. Without this
 * table a retried payment becomes two payments, and the second one is
 * indistinguishable from a real repayment made minutes later.
 *
 * The request body is kept alongside the verdict. When a customer says they
 * paid and the ledger says otherwise, the argument is settled by what the
 * handset actually sent, not by what somebody remembers it sending.
 */
final class OperationLog
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /**
     * The verdict already recorded for this op_id, or null if it is new.
     */
    public function find(string $opId): ?OperationOutcome
    {
        try {
            $row = $this->db->queryOne(
                'SELECT op_id, status, rev, error FROM sync_operations WHERE op_id = :op',
                ['op' => $opId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to read the sync operation log.',
                layer: 'repository.sync.operations',
                context: ['op_id' => $opId],
                previous: $e,
            );
        }

        if ($row === null) {
            return null;
        }

        return OperationOutcome::replayed(
            (string) $row['op_id'],
            $row['rev'] === null ? null : (int) $row['rev'],
            $row['error'] === null ? null : (string) $row['error'],
        );
    }

    /**
     * Record how an operation ended.
     *
     * A CONFLICT is deliberately NOT recorded. It is not a settled answer: the
     * device is expected to pull, re-decide and send the work again — under the
     * same op_id, because it is the same intent. Storing the conflict would
     * make that second attempt replay the refusal forever.
     */
    public function record(Operation $op, OperationOutcome $outcome, string $deviceId, ?string $userId): void
    {
        if ($outcome->status === OperationStatus::Conflict) {
            return;
        }

        try {
            $this->db->upsert(
                'sync_operations',
                [
                    'op_id'       => $op->opId,
                    'device_id'   => $deviceId,
                    'user_id'     => $userId,
                    'entity'      => $op->entity,
                    'entity_id'   => $op->id,
                    'action'      => $op->action->value,
                    'status'      => $outcome->status->value,
                    'rev'         => $outcome->rev,
                    'error'       => $outcome->error,
                    'request'     => json_encode($op->fields, JSON_THROW_ON_ERROR),
                    'received_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s'),
                ],
                ['op_id'],
                // Nothing is updated on a repeat: the FIRST verdict is the one
                // that counts, and a concurrent duplicate of the same op_id
                // must not overwrite it.
                [],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to record a sync operation.',
                layer: 'repository.sync.operations',
                context: ['op_id' => $op->opId],
                previous: $e,
            );
        }
    }
}
