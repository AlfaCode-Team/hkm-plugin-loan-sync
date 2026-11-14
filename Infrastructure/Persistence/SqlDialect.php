<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;

/**
 * The three things portable SQL cannot be written without knowing the driver.
 *
 * The kernel supports MySQL, PostgreSQL, SQLite and SQL Server, and those four
 * disagree on exactly the constructs a repository cannot avoid: how a page is
 * taken, how a row is locked, and what a boolean looks like coming back. Every
 * other statement in this module is written once and runs on all four.
 *
 * Duplicated verbatim in each akasente module rather than shared, because a
 * module may not import another module's internals, and a dialect is not a
 * business capability worth publishing a contract for.
 */
final readonly class SqlDialect
{
    public const string MYSQL  = 'mysql';
    public const string PGSQL  = 'pgsql';
    public const string SQLITE = 'sqlite';
    public const string SQLSRV = 'sqlsrv';

    private function __construct(public string $name)
    {
    }

    /**
     * Name the driver behind a port, or refuse.
     *
     * Three steps, and the order matters:
     *
     *  1. the optional DriverAware capability, which is the declared contract;
     *  2. a `driver()` method on the bound port. The Database plugin's adapter
     *     (v2.2.0) HAS this method but does not declare `DriverAware`, so
     *     `instanceof` alone misses the one adapter this actually runs against;
     *  3. `DB_DRIVER`, for a port that offers neither — which includes that
     *     plugin's own PooledDatabaseAdapter.
     *
     * When nothing can name the driver it THROWS. There is no safe default:
     * guessing wrong writes `LIMIT` to SQL Server (a syntax error, loud) or
     * drops a `FOR UPDATE` on MySQL (silent, and the reason this class exists).
     *
     * @throws RepositoryException
     */
    public static function of(DatabasePort $db): self
    {
        $raw = match (true) {
            $db instanceof DriverAware         => $db->driver(),
            method_exists($db, 'driver')       => (string) $db->driver(),
            default                            => (string) (env('DB_DRIVER') ?: ''),
        };

        return self::named($raw);
    }

    /** @throws RepositoryException */
    public static function named(string $driver): self
    {
        $name = match (strtolower(trim($driver))) {
            'mysql', 'mariadb'                        => self::MYSQL,
            'pgsql', 'postgres', 'postgresql'         => self::PGSQL,
            'sqlite', 'sqlite3'                       => self::SQLITE,
            'sqlsrv', 'mssql', 'sqlserver', 'dblib'   => self::SQLSRV,
            default                                   => '',
        };

        if ($name === '') {
            throw new RepositoryException(
                $driver === ''
                    ? 'Cannot determine the SQL driver. Bind a DatabasePort that implements '
                        . 'DriverAware or exposes driver(), or set DB_DRIVER.'
                    : sprintf('Unsupported SQL driver "%s". Supported: mysql, pgsql, sqlite, sqlsrv.', $driver),
                layer: 'repository.dialect',
            );
        }

        return new self($name);
    }

    /**
     * The paging clause, for a query that ALREADY ends in an ORDER BY.
     *
     * SQL Server has no LIMIT. Its OFFSET/FETCH is only legal after an ORDER
     * BY — which is not a restriction worth working around, because a page
     * taken without one is not reproducible on any driver.
     *
     * Both numbers are clamped integers written into the SQL rather than
     * bound: MySQL will not accept a placeholder in LIMIT under native
     * prepares, and SQL Server will not accept one in FETCH NEXT.
     */
    public function page(int $limit, int $offset = 0): string
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        return $this->name === self::SQLSRV
            ? sprintf(' OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit)
            : sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
    }

    /**
     * The lock hint that goes immediately AFTER a table name in FROM.
     *
     * SQL Server spells a row lock as a table hint rather than a trailing
     * clause, and UPDLOCK alone is not enough: without HOLDLOCK the lock is
     * released at the end of the statement instead of the end of the
     * transaction, so a second writer can read the same counter.
     */
    public function tableLock(): string
    {
        return $this->name === self::SQLSRV ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    }

    /**
     * The lock clause that goes at the very END of a SELECT.
     *
     * Empty on SQLite, which serialises writers for the whole transaction
     * anyway, and on SQL Server, which took its lock in tableLock().
     */
    public function rowLock(): string
    {
        return match ($this->name) {
            self::MYSQL, self::PGSQL => ' FOR UPDATE',
            default                  => '',
        };
    }

    /**
     * A fetched SQL boolean, as PHP, from any driver.
     *
     * The four drivers store a boolean four ways — TINYINT(1), BOOLEAN, a
     * dynamically typed INTEGER and BIT — and PDO hands them back
     * accordingly. PostgreSQL is the one that bites: with
     * PDO::ATTR_STRINGIFY_FETCHES on, or on any PHP before 8.1, a BOOLEAN
     * column arrives as the string 'f', and `(bool) 'f'` is TRUE.
     */
    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '', '0', 'f', 'false', 'off', 'no' => false,
                default                            => true,
            };
        }

        return (bool) $value;
    }

    /**
     * The canonical wire value for a boolean column: 1 or 0, never 't'/true.
     *
     * A pull page is JSON a handset parses. Left to the driver, the same row
     * serialises as `1` from MySQL and `true` from PostgreSQL, so the wire
     * format would depend on where the server happens to be deployed.
     */
    public static function boolToWire(mixed $value): int
    {
        return self::bool($value) ? 1 : 0;
    }
}
