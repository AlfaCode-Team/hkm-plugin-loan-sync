<?php

declare(strict_types=1);

namespace Plugins\LoanSync\API\Contracts;

use Plugins\LoanSync\Domain\PullPage;
use Plugins\LoanSync\Domain\ShardClaim;

/**
 * The published surface of the sync domain.
 *
 * The server is the source of truth; a device holds a replica. Nothing here
 * merges two versions of a row: when a device wrote against a rev the server
 * has moved past, the operation comes back as a conflict and the DEVICE pulls
 * and decides again. An automatic merge of two field officers' edits to the
 * same loan is a silent answer to a question only a person can settle.
 */
interface SyncServiceContract
{
    /**
     * Apply a device's outbox, in the order it was queued.
     *
     * Order is load-bearing: a loan references a customer and a payment
     * references a loan, so the queue is applied front to back and a failure
     * does not reorder what follows.
     *
     * @param  list<array<string, mixed>> $operations raw envelopes from the device
     * @return array<string, mixed>       the per-operation verdicts and the cursor
     */
    public function push(string $deviceId, array $operations): array;

    /**
     * Everything that changed after `cursor`, oldest first.
     *
     * @param list<string>|null $entities restrict to these, or null for all
     */
    public function pull(string $deviceId, int $cursor, ?array $entities = null, int $limit = 500): PullPage;

    /**
     * The device's id shard, claimed on first call and returned unchanged after.
     *
     * Calling it twice from the same device is not an error and does not burn a
     * second shard — a handset that lost its local copy of the claim must be
     * able to ask again and get the same answer, or it would mint ids in a
     * range another device already owns.
     */
    public function claimShard(string $deviceId): ShardClaim;

    /**
     * Record or refresh a handset, and return what it is allowed to do.
     *
     * @param  array<string, mixed> $attributes platform, app_version, model, push_token
     * @return array<string, mixed>
     */
    public function registerDevice(string $deviceId, array $attributes): array;
}
