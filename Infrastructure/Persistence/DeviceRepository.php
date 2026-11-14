<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Every handset that has ever synced with this business, and what it may do.
 *
 * `blocked` is the kill switch for a phone that was lost or stolen. It is
 * checked on every push and pull rather than only at sign-in, because the
 * device that matters is the one still holding a week of customer records and
 * a signed-in session, and nobody is going to ask it to log out.
 */
final class DeviceRepository
{
    /** @var list<string> attributes a handset may set about itself */
    private const array SELF_REPORTED = ['platform', 'app_version', 'model', 'push_token'];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(string $deviceId): ?array
    {
        try {
            return $this->db->queryOne(
                'SELECT * FROM devices WHERE id = :id',
                ['id' => $deviceId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to read the device.',
                layer: 'repository.sync.device',
                context: ['device_id' => $deviceId],
                previous: $e,
            );
        }
    }

    /**
     * Record a handset, or refresh what it reports about itself.
     *
     * `blocked` is NOT in the writable set. A blocked phone re-registering
     * must not be able to unblock itself, and it will certainly try: that is
     * what an ordinary app restart looks like from the server's side.
     *
     * @param array<string, mixed> $attributes
     */
    public function register(string $deviceId, ?string $userId, array $attributes): void
    {
        $now = $this->now();

        $values = [
            'id'         => $deviceId,
            'user_id'    => $userId,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        foreach (self::SELF_REPORTED as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $values[$key] = (string) $attributes[$key];
            }
        }

        try {
            $this->db->upsert(
                'devices',
                $values,
                ['id'],
                // created_at is deliberately absent from the update set: a
                // re-registration must not reset when this handset was first
                // seen, which is the field an investigation starts from.
                array_values(array_diff(array_keys($values), ['id', 'created_at'])),
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to register the device.',
                layer: 'repository.sync.device',
                context: ['device_id' => $deviceId],
                previous: $e,
            );
        }
    }

    public function isBlocked(string $deviceId): bool
    {
        $row = $this->find($deviceId);

        // Not `(bool)`: PostgreSQL's BOOLEAN can arrive as the string 'f',
        // and `(bool) 'f'` is true — which would block every device.
        return $row !== null && SqlDialect::bool($row['blocked']);
    }

    public function recordPush(string $deviceId): void
    {
        $this->stamp($deviceId, 'last_push_at', null, null);
    }

    public function recordPull(string $deviceId, int $cursor): void
    {
        $this->stamp($deviceId, 'last_pull_at', 'last_pull_rev', $cursor);
    }

    public function attachShard(string $deviceId, int $shard): void
    {
        try {
            $this->db->execute(
                'UPDATE devices SET shard = :shard, updated_at = :now WHERE id = :id',
                ['shard' => $shard, 'now' => $this->now(), 'id' => $deviceId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to attach the shard to the device.',
                layer: 'repository.sync.device',
                context: ['device_id' => $deviceId, 'shard' => $shard],
                previous: $e,
            );
        }
    }

    private function stamp(string $deviceId, string $timeColumn, ?string $revColumn, ?int $rev): void
    {
        $sql    = sprintf('UPDATE devices SET %s = :at', $timeColumn);
        $params = ['at' => $this->now(), 'id' => $deviceId];

        if ($revColumn !== null && $rev !== null) {
            // The recorded cursor only ever moves forward. A device replaying
            // an old pull must not rewind what the server believes it has.
            $sql .= sprintf(', %s = :rev', $revColumn);
            $params['rev'] = $rev;
            $sql .= sprintf(' WHERE id = :id AND (%s IS NULL OR %s < :rev_guard)', $revColumn, $revColumn);
            $params['rev_guard'] = $rev;

            try {
                $this->db->execute($sql, $params);
            } catch (\Throwable) {
                // A missed heartbeat must not fail the pull it belongs to.
            }

            return;
        }

        $sql .= ' WHERE id = :id';

        try {
            $this->db->execute($sql, $params);
        } catch (\Throwable) {
            // As above: best effort.
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
