<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanSync\Domain\ShardClaim;

/**
 * Hands each device an exclusive slice of the id space, exactly once.
 *
 * The claim is made with an INSERT against a UNIQUE device_id, not with
 * "find the highest shard and add one". Two handsets registering at the same
 * moment would both read the same highest shard and both add one, and the
 * duplicate would not surface until each had minted a few hundred ids offline
 * — by which time both sets are on paper in a customer's hands.
 *
 * So the database decides. A losing racer gets a constraint violation, re-reads
 * its own row and finds the shard the winning attempt assigned it.
 */
final class ShardRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function forDevice(string $deviceId): ?int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT shard FROM id_shards WHERE device_id = :device',
                ['device' => $deviceId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to read the id shard.',
                layer: 'repository.sync.shard',
                context: ['device_id' => $deviceId],
                previous: $e,
            );
        }

        return $row === null ? null : (int) $row['shard'];
    }

    /**
     * Claim the lowest free shard for this device.
     *
     * Retries on collision because the gap it picked may be taken between the
     * read and the insert. The loop is bounded: an unbounded retry against a
     * genuinely exhausted range would spin forever rather than report.
     */
    public function claim(string $deviceId, ?string $userId): ShardClaim
    {
        $existing = $this->forDevice($deviceId);

        if ($existing !== null) {
            return new ShardClaim($existing, $deviceId, freshlyClaimed: false);
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $this->lowestFree();

            try {
                $this->db->execute(
                    'INSERT INTO id_shards (shard, device_id, claimed_by_user, claimed_at, last_seen_at)'
                    . ' VALUES (:shard, :device, :user, :at, :seen)',
                    [
                        'shard'  => $candidate,
                        'device' => $deviceId,
                        'user'   => $userId,
                        'at'     => $now,
                        'seen'   => $now,
                    ],
                );

                return new ShardClaim($candidate, $deviceId, freshlyClaimed: true);
            } catch (\Throwable) {
                // Either this shard was taken, or this device was claimed by a
                // concurrent request. If it was the device, that request's
                // shard is now ours and asking again returns it.
                $mine = $this->forDevice($deviceId);

                if ($mine !== null) {
                    return new ShardClaim($mine, $deviceId, freshlyClaimed: false);
                }
            }
        }

        throw new RepositoryException(
            'Could not claim an id shard after 8 attempts.',
            layer: 'repository.sync.shard',
            context: ['device_id' => $deviceId],
        );
    }

    public function touch(string $deviceId): void
    {
        try {
            $this->db->execute(
                'UPDATE id_shards SET last_seen_at = :seen WHERE device_id = :device',
                [
                    'seen'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s'),
                    'device' => $deviceId,
                ],
            );
        } catch (\Throwable) {
            // Best effort. A missed heartbeat must never fail a push that
            // otherwise succeeded.
        }
    }

    /**
     * The lowest shard not yet claimed, so a device replaced after a year does
     * not push the range towards its ceiling.
     */
    private function lowestFree(): int
    {
        try {
            $row = $this->db->queryOne('SELECT MAX(shard) AS top FROM id_shards');
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to scan the id shard range.',
                layer: 'repository.sync.shard',
                previous: $e,
            );
        }

        $top = $row === null || $row['top'] === null ? 0 : (int) $row['top'];

        // Shard 0 is the legacy range (everything minted before sharding, the
        // Firestore import included) and is never handed to a device.
        $next = max($top + 1, ShardClaim::FIRST);

        if ($next > ShardClaim::LAST) {
            throw new RepositoryException(
                'The id shard range is exhausted.',
                layer: 'repository.sync.shard',
                context: ['last' => ShardClaim::LAST],
            );
        }

        return $next;
    }
}
