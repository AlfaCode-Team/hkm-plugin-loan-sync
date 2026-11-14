<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanSync\Domain\EntityCatalogue;

/**
 * Reads and writes the synced business tables.
 *
 * Every table and column name reaching SQL here comes from {@see EntityCatalogue},
 * which is generated from the migrated schema — never from the request. Values
 * are always bound. That split is what lets one generic repository serve
 * fifteen tables without the request being able to name a sixteenth.
 */
final class EntityRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(EntityCatalogue $entity, int $id): ?array
    {
        try {
            $row = $this->db->queryOne(
                sprintf('SELECT * FROM %s WHERE id = :id', $entity->table),
                ['id' => $id],
            );

            return $row === null ? null : $this->canonicalise($entity, $row);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                sprintf('Failed to read %s [%d].', $entity->name, $id),
                layer: 'repository.sync.entity',
                context: ['entity' => $entity->name, 'id' => $id],
                previous: $e,
            );
        }
    }

    /**
     * Insert a row the device minted.
     *
     * @param array<string, mixed> $fields already filtered to the allowlist
     */
    public function insert(EntityCatalogue $entity, int $id, array $fields, int $rev, string $deviceId): void
    {
        $now = $this->now();

        $values = $fields + [
            'id'         => $id,
            'rev'        => $rev,
            'device_id'  => $deviceId,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $columns      = array_keys($values);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

        try {
            $this->db->execute(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $entity->table,
                    implode(', ', $columns),
                    implode(', ', $placeholders),
                ),
                $values,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                sprintf('Failed to insert %s [%d].', $entity->name, $id),
                layer: 'repository.sync.entity',
                context: ['entity' => $entity->name, 'id' => $id],
                previous: $e,
            );
        }
    }

    /**
     * Update a row in place.
     *
     * @param array<string, mixed> $fields already filtered to the allowlist
     */
    public function update(EntityCatalogue $entity, int $id, array $fields, int $rev, string $deviceId): void
    {
        $values = $fields + [
            'rev'        => $rev,
            'device_id'  => $deviceId,
            'updated_at' => $this->now(),
        ];

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($values),
        ));

        try {
            $this->db->execute(
                sprintf('UPDATE %s SET %s WHERE id = :where_id', $entity->table, $assignments),
                $values + ['where_id' => $id],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                sprintf('Failed to update %s [%d].', $entity->name, $id),
                layer: 'repository.sync.entity',
                context: ['entity' => $entity->name, 'id' => $id],
                previous: $e,
            );
        }
    }

    /**
     * Tombstone a row: set `deleted_at`, take a new rev, leave the data alone.
     *
     * The row stays because the tombstone has to travel. It also stays because
     * a deleted loan is still evidence — somebody collected money against it.
     */
    public function tombstone(EntityCatalogue $entity, int $id, int $rev, string $deviceId): void
    {
        $now = $this->now();

        try {
            $this->db->execute(
                sprintf(
                    'UPDATE %s SET deleted_at = :deleted, rev = :rev, device_id = :device,'
                    . ' updated_at = :updated WHERE id = :id',
                    $entity->table,
                ),
                [
                    'deleted' => $now,
                    'rev'     => $rev,
                    'device'  => $deviceId,
                    'updated' => $now,
                    'id'      => $id,
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                sprintf('Failed to tombstone %s [%d].', $entity->name, $id),
                layer: 'repository.sync.entity',
                context: ['entity' => $entity->name, 'id' => $id],
                previous: $e,
            );
        }
    }

    /**
     * Rows of one entity above `cursor`, oldest rev first.
     *
     * Ordering by rev is what makes the page resumable: the caller can stop
     * anywhere and the next request continues from the last rev it actually
     * handed over. `limit + 1` is fetched so the caller can tell a full page
     * from the last one without a second COUNT.
     *
     * Tombstoned rows are INCLUDED. A delete is a change like any other, and a
     * device that filtered them out would keep the row forever.
     *
     * @return list<array<string, mixed>>
     */
    public function changedSince(EntityCatalogue $entity, int $cursor, int $limit): array
    {
        try {
            $rows = $this->db->query(
                sprintf(
                    'SELECT * FROM %s WHERE rev > :cursor ORDER BY rev ASC%s',
                    $entity->table,
                    SqlDialect::of($this->db)->page($limit + 1),
                ),
                ['cursor' => $cursor],
            );

            return array_map(fn(array $row): array => $this->canonicalise($entity, $row), $rows);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                sprintf('Failed to read changes for %s.', $entity->name),
                layer: 'repository.sync.entity',
                context: ['entity' => $entity->name, 'cursor' => $cursor],
                previous: $e,
            );
        }
    }

    /**
     * One wire shape for a row, whichever driver produced it.
     *
     * A pull page is JSON that a handset parses, and the four drivers hand
     * back a boolean column in four different shapes — PostgreSQL's BOOLEAN
     * arrives as a PHP bool (or the string 't' when fetches are stringified),
     * MySQL's TINYINT(1) and SQL Server's BIT as 1/0. Without this, moving
     * the server from one database to another would silently change the
     * payload every device in the field is already parsing.
     *
     * 1/0 is the chosen form because it is what the devices already receive
     * and what every driver accepts back on a push.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function canonicalise(EntityCatalogue $entity, array $row): array
    {
        foreach ($entity->booleans as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = SqlDialect::boolToWire($row[$column]);
            }
        }

        return $row;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
