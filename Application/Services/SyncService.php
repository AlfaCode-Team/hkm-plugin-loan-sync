<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanSync\API\Contracts\SyncServiceContract;
use Plugins\LoanSync\Domain\EntityCatalogue;
use Plugins\LoanSync\Domain\Operation;
use Plugins\LoanSync\Domain\OperationOutcome;
use Plugins\LoanSync\Domain\PullPage;
use Plugins\LoanSync\Domain\ShardClaim;
use Plugins\LoanSync\Domain\SyncAction;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\OperationLog;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\LoanSync\Infrastructure\Persistence\ShardRepository;

/**
 * Push and pull between the server (source of truth) and a device (replica).
 *
 * The rules this class exists to hold, each of which is a decision rather than
 * an implementation detail:
 *
 *  1. ONE TRANSACTION PER OPERATION, not one per push. A field officer's
 *     outbox is a day of unrelated work — eleven customers, four loans, thirty
 *     payments. Wrapping the batch in a single transaction means one bad row
 *     rolls back the other forty-four and the phone retries the whole day,
 *     forever, because the bad row is still bad. Per-operation commits let the
 *     good work land and the bad row come back named.
 *
 *  2. IDEMPOTENT BY op_id, checked before anything else. A retry over a dropped
 *     connection is the normal case.
 *
 *  3. ORDER IS PRESERVED. A loan references a customer; a payment references a
 *     loan. The queue is applied front to back.
 *
 *  4. NOTHING IS MERGED. When the device wrote against a rev the server has
 *     moved past, it is told so and pulls. Two officers editing one loan is a
 *     question for a person.
 *
 *  5. THE SERVER NEVER RE-RUNS ALLOCATION. A payment's principal/interest/
 *     penalty split is stored as the device computed it, because the device
 *     printed it on a receipt the customer is holding. Where the ledger and the
 *     schedule disagree, that is a reconciliation for a human — never a
 *     recalculation that silently contradicts a piece of paper.
 */
final class SyncService implements SyncServiceContract
{
    /** A page no device asked to be this big, and none should receive. */
    private const int MAX_LIMIT = 1000;

    /** Beyond this, a single push is refused rather than held open for minutes. */
    private const int MAX_OPERATIONS = 500;

    public function __construct(
        private readonly EntityRepository $entities,
        private readonly RevisionRepository $revisions,
        private readonly OperationLog $operations,
        private readonly ShardRepository $shards,
        private readonly DeviceRepository $devices,
        private readonly TransactionManager $transaction,
        private readonly Identity $identity,
        private readonly ?LoggerPort $logger = null,
    ) {
    }

    public function push(string $deviceId, array $operations): array
    {
        $this->guardDevice($deviceId);

        if (count($operations) > self::MAX_OPERATIONS) {
            throw new ServiceException(
                sprintf('A push carries at most %d operations.', self::MAX_OPERATIONS),
                layer: 'service.sync',
                context: ['sent' => count($operations)],
            );
        }

        $results  = [];
        $userId   = $this->identity->isGuest() ? null : $this->identity->userId;

        foreach ($operations as $raw) {
            if (!is_array($raw)) {
                $results[] = OperationOutcome::rejected('', 'Each operation must be an object.')->toArray();
                continue;
            }

            $results[] = $this->applyOne($raw, $deviceId, $userId)->toArray();
        }

        $this->devices->recordPush($deviceId);
        $this->shards->touch($deviceId);

        return [
            'results' => $results,
            // The device learns where the server is now, but it must still PULL
            // to reach it. This is a signal that there is something to fetch,
            // never a cursor to adopt — adopting it would skip every row
            // between the device's cursor and this number.
            'server_rev' => $this->revisions->current(),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function applyOne(array $raw, string $deviceId, ?string $userId): OperationOutcome
    {
        try {
            $op = Operation::fromArray($raw);
        } catch (\DomainException $e) {
            return OperationOutcome::rejected(
                trim((string) ($raw['op_id'] ?? '')),
                $e->getMessage(),
            );
        }

        // Idempotency first: a settled op_id is answered from the ledger and
        // nothing is written.
        $already = $this->operations->find($op->opId);

        if ($already !== null) {
            return $already;
        }

        $entity = $op->catalogue();

        // Create and update have different allowlists: a row may be write-once
        // except for the few fields allowed to move afterwards (the payment
        // ledger is the reason this distinction exists).
        [$fields, $ignored] = $op->action === SyncAction::Update
            ? $entity->filterUpdate($op->fields)
            : $entity->filter($op->fields);

        $this->transaction->begin();

        try {
            $existing = $this->entities->find($entity, $op->id);

            $outcome = match ($op->action) {
                SyncAction::Create => $this->create($entity, $op, $fields, $ignored, $existing, $deviceId),
                SyncAction::Update => $this->update($entity, $op, $fields, $ignored, $existing, $deviceId),
                SyncAction::Delete => $this->delete($entity, $op, $existing, $deviceId),
            };

            $this->operations->record($op, $outcome, $deviceId, $userId);

            $this->transaction->commit();

            return $outcome;
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            $this->logger?->error('sync.operation_failed', [
                'op_id'  => $op->opId,
                'entity' => $op->entity,
                'id'     => $op->id,
                'error'  => $e->getMessage(),
            ]);

            // Refused, not recorded: the cause may be transient (a deadlock, a
            // dropped connection), and recording it would make a later, valid
            // retry of this op_id replay the failure forever.
            return OperationOutcome::rejected($op->opId, 'The server could not apply this operation.');
        }
    }

    /**
     * @param array<string, mixed>      $fields
     * @param list<string>              $ignored
     * @param array<string, mixed>|null $existing
     */
    private function create(
        EntityCatalogue $entity,
        Operation $op,
        array $fields,
        array $ignored,
        ?array $existing,
        string $deviceId,
    ): OperationOutcome {
        if ($existing !== null) {
            // The id is already here under a DIFFERENT op_id — the device
            // re-queued work it had already pushed, e.g. after restoring a
            // backup. Treat it as an update rather than refusing: the row it
            // describes is the row that exists, and refusing would strand the
            // handset with an entry it can never clear.
            return $this->update($entity, $op, $fields, $ignored, $existing, $deviceId);
        }

        $rev = $this->revisions->next();
        $this->entities->insert($entity, $op->id, $fields, $rev, $deviceId);

        return OperationOutcome::applied($op->opId, $rev, $ignored);
    }

    /**
     * @param array<string, mixed>      $fields
     * @param list<string>              $ignored
     * @param array<string, mixed>|null $existing
     */
    private function update(
        EntityCatalogue $entity,
        Operation $op,
        array $fields,
        array $ignored,
        ?array $existing,
        string $deviceId,
    ): OperationOutcome {
        if ($existing === null) {
            return OperationOutcome::rejected(
                $op->opId,
                sprintf('%s [%d] does not exist on the server.', $entity->name, $op->id),
            );
        }

        $serverRev = (int) ($existing['rev'] ?? 0);

        // A device that states the rev it edited is held to it. One that states
        // nothing is trusted — which is how a legacy handset that predates the
        // field keeps working, and is also why base_rev is worth sending.
        if ($op->baseRev !== null && $op->baseRev < $serverRev) {
            return OperationOutcome::conflict($op->opId, $serverRev);
        }

        if ($fields === []) {
            return OperationOutcome::rejected($op->opId, 'An update carried no recognised fields.');
        }

        $rev = $this->revisions->next();
        $this->entities->update($entity, $op->id, $fields, $rev, $deviceId);

        return OperationOutcome::applied($op->opId, $rev, $ignored);
    }

    /** @param array<string, mixed>|null $existing */
    private function delete(
        EntityCatalogue $entity,
        Operation $op,
        ?array $existing,
        string $deviceId,
    ): OperationOutcome {
        if (!$entity->deletable) {
            // Permanent by design. The ledger is the case this exists for: a
            // payment is superseded, never deleted, because somebody is
            // holding the receipt for it.
            return OperationOutcome::rejected(
                $op->opId,
                sprintf('%s records cannot be deleted; supersede the row instead.', $entity->name),
            );
        }

        if ($existing === null) {
            return OperationOutcome::rejected(
                $op->opId,
                sprintf('%s [%d] does not exist on the server.', $entity->name, $op->id),
            );
        }

        if (($existing['deleted_at'] ?? null) !== null) {
            // Already a tombstone. Taking a second rev for it would wake the
            // row up on every device for no reason.
            return OperationOutcome::applied($op->opId, (int) ($existing['rev'] ?? 0));
        }

        $rev = $this->revisions->next();
        $this->entities->tombstone($entity, $op->id, $rev, $deviceId);

        return OperationOutcome::applied($op->opId, $rev);
    }

    public function pull(string $deviceId, int $cursor, ?array $entities = null, int $limit = 500): PullPage
    {
        $this->guardDevice($deviceId);

        $cursor = max(0, $cursor);
        $limit  = max(1, min($limit, self::MAX_LIMIT));

        $names = $entities === null
            ? EntityCatalogue::names()
            : array_values(array_filter($entities, static fn(string $n): bool => EntityCatalogue::has($n)));

        // A PAGE IS A PREFIX OF THE GLOBAL REV ORDER, AND IT HAS TO BE.
        //
        // There is ONE cursor, so "where this device has got to" is a single
        // number. That number can only be honest if everything at or below it
        // has been handed over — across every entity, not per table. So the
        // candidates are gathered from all entities, merged, sorted by rev, and
        // cut at the limit. The cursor is then the rev of the last row in the
        // page, and every row below it provably went with it.
        //
        // Filling the page entity by entity instead is the obvious
        // implementation and it is wrong twice over. Take 5 customers at revs
        // 1-9 and leave expenses unread: the cursor cannot move to 9, because
        // expense rev 2 was never sent and would be skipped — but it cannot
        // stay at 0 either, or the next pull returns the same five customers
        // forever. That is a livelock, and from the handset it looks exactly
        // like a sync that hangs.
        //
        // The cost is one indexed query per entity per pull. Each is
        // `WHERE rev > ? ORDER BY rev LIMIT n` against idx_<table>_rev.
        $pool = [];

        foreach ($names as $name) {
            $entity = EntityCatalogue::get($name);

            foreach ($this->entities->changedSince($entity, $cursor, $limit) as $row) {
                $pool[] = ['entity' => $name, 'rev' => (int) ($row['rev'] ?? 0), 'row' => $row];
            }
        }

        // Revs come from one per-tenant counter, so they are unique and this
        // order is total — no tie-break is needed or possible.
        usort($pool, static fn(array $a, array $b): int => $a['rev'] <=> $b['rev']);

        $hasMore = count($pool) > $limit;
        $page    = array_slice($pool, 0, $limit);

        $changes   = [];
        $cursorOut = $cursor;

        foreach ($page as $item) {
            $changes[$item['entity']][] = $item['row'];
            $cursorOut                  = $item['rev'];   // sorted: the last wins
        }

        $this->devices->recordPull($deviceId, $cursorOut);

        return new PullPage(
            changes: $changes,
            cursor:  $cursorOut,
            hasMore: $hasMore,
            count:   count($page),
        );
    }

    public function claimShard(string $deviceId): ShardClaim
    {
        $this->guardDevice($deviceId);

        $claim = $this->shards->claim(
            $deviceId,
            $this->identity->isGuest() ? null : $this->identity->userId,
        );

        $this->devices->attachShard($deviceId, $claim->shard);

        return $claim;
    }

    public function registerDevice(string $deviceId, array $attributes): array
    {
        if ($deviceId === '' || strlen($deviceId) > 64) {
            throw new ServiceException(
                'A device id of 1 to 64 characters is required.',
                layer: 'service.sync',
            );
        }

        if ($this->devices->isBlocked($deviceId)) {
            // Re-registering must not clear a block, and the handset is told
            // plainly rather than left to retry into a wall.
            throw new ServiceException(
                'This device has been blocked.',
                layer: 'service.sync',
                context: ['device_id' => $deviceId],
            );
        }

        $this->devices->register(
            $deviceId,
            $this->identity->isGuest() ? null : $this->identity->userId,
            $attributes,
        );

        $row = $this->devices->find($deviceId) ?? [];

        return [
            'device_id'     => $deviceId,
            'shard'         => $row['shard'] === null ? null : (int) ($row['shard'] ?? 0),
            'last_pull_rev' => (int) ($row['last_pull_rev'] ?? 0),
            'server_rev'    => $this->revisions->current(),
            'entities'      => EntityCatalogue::names(),
        ];
    }

    private function guardDevice(string $deviceId): void
    {
        if ($deviceId === '' || strlen($deviceId) > 64) {
            throw new ServiceException(
                'A device id of 1 to 64 characters is required.',
                layer: 'service.sync',
            );
        }

        if ($this->devices->isBlocked($deviceId)) {
            throw new ServiceException(
                'This device has been blocked.',
                layer: 'service.sync',
                context: ['device_id' => $deviceId],
            );
        }
    }
}
