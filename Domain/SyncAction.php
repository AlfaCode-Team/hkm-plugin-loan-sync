<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * What an operation does to its subject.
 *
 * `Delete` is a TOMBSTONE, never a DELETE statement. A row that simply vanished
 * from the server would live forever on every handset that already pulled it:
 * the puller asks for rows changed since its cursor, and a row that is gone
 * changes nothing. So a delete is an update that sets `deleted_at` and takes a
 * new `rev`, which is exactly what makes it travel.
 */
enum SyncAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public static function parse(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \DomainException(sprintf(
                'Unknown sync action [%s]. Expected create, update or delete.',
                $value,
            ));
    }
}
