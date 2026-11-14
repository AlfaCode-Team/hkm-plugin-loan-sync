<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync\Support;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PDO;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Drivers\SqlServerConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

/**
 * A throwaway tenant database, on whichever driver the run asks for.
 *
 * WHY THIS EXISTS
 * ---------------
 * These tests drive the REAL adapter against a REAL schema built by the REAL
 * migrations — a fake DatabasePort would hide the two things most worth
 * knowing, whether the SQL is valid and whether the columns exist. But running
 * only on SQLite proves very little: it has no strict mode, no real column
 * types, it rewrites a repeated named placeholder, and it serialises writers
 * for free. Every portability defect this suite has ever caught was invisible
 * there and fatal on MySQL or PostgreSQL.
 *
 *     HKM_TEST_DB=sqlite   (the default — no server needed)
 *     HKM_TEST_DB=mysql    HKM_TEST_DB_USERNAME=… HKM_TEST_DB_PASSWORD=…
 *     HKM_TEST_DB=pgsql    HKM_TEST_DB_HOST=…     HKM_TEST_DB_PORT=…
 *     HKM_TEST_DB=sqlsrv
 *
 * WHERE THE MIGRATIONS COME FROM
 * ------------------------------
 * From the PLUGINS themselves, named as provider classes rather than paths.
 * Each provider's file is located by reflection and its package root taken
 * from there, so the same call works whether the plugin is a checkout sitting
 * beside this one, a directory under a project's `plugins/`, or a package in
 * `vendor/` — three layouts no hard-coded relative path survives.
 *
 * TEARDOWN
 * --------
 * {@see destroy()} removes exactly the database this object created, by the
 * name it minted, and refuses anything without the prefix. It never
 * enumerates databases and never removes "everything except" — deleting by
 * exclusion is how a real business was once destroyed.
 */
final class TestDatabase
{
    public const string PREFIX = 'hkm_test_';

    private function __construct(
        public readonly DatabasePort $port,
        public readonly string $driver,
        public readonly string $name,
        /** Set only for SQLite: the file to unlink. */
        private readonly ?string $path,
        /** Set only for server drivers: how to reach the server to drop the database. */
        private readonly ?array $server,
    ) {
    }

    /** The driver this run is configured for, without provisioning anything. */
    public static function driver(): string
    {
        $raw = strtolower(trim((string) (getenv('HKM_TEST_DB') ?: 'sqlite')));

        return match ($raw) {
            'mysql', 'mariadb'                => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlsrv', 'mssql', 'sqlserver'    => 'sqlsrv',
            'sqlite', 'sqlite3', ''           => 'sqlite',
            default => throw new \RuntimeException(sprintf(
                'HKM_TEST_DB=%s is not one of sqlite, mysql, pgsql, sqlsrv.',
                $raw,
            )),
        };
    }

    /**
     * Provision an empty database and run the real migrations into it.
     *
     * @param string            $label     short [a-z0-9_] suite name, so a stray
     *                                     database says which run left it behind
     * @param list<class-string> $providers plugin providers whose
     *                                     database/tenant-template to migrate
     */
    public static function provision(string $label, array $providers): self
    {
        if (preg_match('/^[a-z0-9_]{1,24}$/', $label) !== 1) {
            throw new \InvalidArgumentException('A test database label must match [a-z0-9_]{1,24}.');
        }

        $paths = self::migrationPaths($providers);
        $driver = self::driver();
        $name   = self::PREFIX . $label . '_' . bin2hex(random_bytes(4));

        return $driver === 'sqlite'
            ? self::sqlite($name, $paths)
            : self::server($driver, $name, $paths);
    }

    /**
     * Keep only the providers whose class is actually on the autoloader.
     *
     * For the sync engine, whose catalogue spans every domain plugin's tables:
     * it has to be testable from a bare clone, with none of them checked out,
     * so a sibling that is absent is left out rather than being an error. The
     * catalogue test says plainly which tables it could not check.
     *
     * @param  list<class-string> $providers
     * @return list<class-string>
     */
    public static function available(array $providers): array
    {
        return array_values(array_filter($providers, static fn(string $c): bool => class_exists($c)));
    }

    /**
     * @param  list<class-string> $providers
     * @return list<string>
     */
    private static function migrationPaths(array $providers): array
    {
        $paths = [];

        foreach ($providers as $provider) {
            if (!class_exists($provider)) {
                throw new \RuntimeException(sprintf(
                    'This suite needs %s, which is not on the autoloader. Check the sibling '
                    . 'plugin is checked out beside this one (see tests/bootstrap.php).',
                    $provider,
                ));
            }

            $root = \dirname((string) (new \ReflectionClass($provider))->getFileName());
            $dir  = $root . '/database/tenant-template';

            if (!is_dir($dir)) {
                throw new \RuntimeException(sprintf('%s ships no tenant-template migrations.', $provider));
            }

            $paths[] = $dir;
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private static function sqlite(string $name, array $paths): self
    {
        $file = sys_get_temp_dir() . '/' . $name . '.sqlite';

        self::migrate(['driver' => 'sqlite', 'database' => $file], $paths);

        return new self(
            port:   new MultiDriverDatabaseAdapter(new SQLiteConfiguration($file)),
            driver: 'sqlite',
            name:   $name,
            path:   $file,
            server: null,
        );
    }

    /** @param list<string> $paths */
    private static function server(string $driver, string $name, array $paths): self
    {
        $server = self::serverSettings($driver);

        self::withAdminConnection($driver, $server, static function (PDO $pdo) use ($driver, $name): void {
            // The name is minted above from a validated label plus hex, so it
            // cannot carry anything but [a-z0-9_]. Identifiers cannot be bound.
            $quoted = $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
            $pdo->exec('CREATE DATABASE ' . $quoted);
        });

        self::migrate([
            'driver'   => $driver,
            'host'     => $server['host'],
            'port'     => $server['port'],
            'database' => $name,
            'username' => $server['username'],
            'password' => $server['password'],
        ], $paths);

        $configuration = match ($driver) {
            'mysql' => new MySQLConfiguration(
                host:     $server['host'],
                port:     $server['port'],
                database: $name,
                username: $server['username'],
                password: $server['password'],
            ),
            'pgsql' => new PostgreSQLConfiguration(
                host:     $server['host'],
                port:     $server['port'],
                database: $name,
                username: $server['username'],
                password: $server['password'],
            ),
            'sqlsrv' => new SqlServerConfiguration(
                server:   $server['host'],
                port:     $server['port'],
                database: $name,
                username: $server['username'],
                password: $server['password'],
                trustServerCertificate: true,
            ),
        };

        return new self(
            port:   new MultiDriverDatabaseAdapter($configuration),
            driver: $driver,
            name:   $name,
            path:   null,
            server: $server,
        );
    }

    /**
     * @param array<string, mixed> $connection
     * @param list<string>         $paths
     */
    private static function migrate(array $connection, array $paths): void
    {
        MigrationServiceFactory::fromConfig([
            'default'        => 'test',
            'connections'    => ['test' => $connection],
            'paths'          => $paths,
            'tracking_table' => 'let_migrations',
            'transactional'  => true,
        ])->run();
    }

    /** @return array{host: string, port: int, username: string, password: string, admin: string} */
    private static function serverSettings(string $driver): array
    {
        $env = static fn(string $key, string $fallback): string
            => (string) (getenv('HKM_TEST_DB_' . $key) ?: $fallback);

        $defaultPort = match ($driver) {
            'mysql'  => '3306',
            'pgsql'  => '5432',
            'sqlsrv' => '1433',
        };

        // The database the CREATE runs against. Every server needs one that
        // already exists, and each names a different one by convention.
        $defaultAdmin = match ($driver) {
            'mysql'  => '',          // MySQL connects with no database selected
            'pgsql'  => 'postgres',
            'sqlsrv' => 'master',
        };

        return [
            'host'     => $env('HOST', '127.0.0.1'),
            'port'     => (int) $env('PORT', $defaultPort),
            'username' => $env('USERNAME', match ($driver) {
                'mysql' => 'root', 'pgsql' => 'postgres', 'sqlsrv' => 'sa',
            }),
            'password' => $env('PASSWORD', ''),
            'admin'    => $env('ADMIN_DB', $defaultAdmin),
        ];
    }

    /**
     * @param array{host: string, port: int, username: string, password: string, admin: string} $server
     * @param callable(PDO): void $work
     */
    private static function withAdminConnection(string $driver, array $server, callable $work): void
    {
        $dsn = match ($driver) {
            'mysql'  => sprintf('mysql:host=%s;port=%d', $server['host'], $server['port'])
                        . ($server['admin'] === '' ? '' : ';dbname=' . $server['admin']),
            'pgsql'  => sprintf('pgsql:host=%s;port=%d;dbname=%s', $server['host'], $server['port'], $server['admin']),
            'sqlsrv' => sprintf('sqlsrv:Server=%s,%d;Database=%s', $server['host'], $server['port'], $server['admin']),
        };

        $pdo = new PDO($dsn, $server['username'], $server['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $work($pdo);
    }

    /**
     * The column names of a table, on whichever driver this is.
     *
     * `PRAGMA table_info` is SQLite's and nothing else's — a test that uses it
     * proves the schema only on the one database that was never in question.
     *
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return array_map(
            static fn(array $row): string => (string) ($row['name'] ?? $row['column_name'] ?? ''),
            $this->describeColumns($table),
        );
    }

    /**
     * The columns a table stores as a BOOLEAN, or null where the driver cannot say.
     *
     * MySQL has no boolean type and reports TINYINT(1); PostgreSQL reports
     * `boolean`; SQL Server reports `bit`.
     *
     * SQLite returns NULL, and that is not a gap in this helper — LetMigrate
     * compiles TINYINT(1) to a plain INTEGER there, because SQLite has
     * affinities rather than types. A boolean column is then indistinguishable
     * from any other integer one, so the honest answer is "this database does
     * not know", not an empty list that would read as "there are none".
     *
     * @return list<string>|null
     */
    public function booleanColumns(string $table): ?array
    {
        if ($this->driver === 'sqlite') {
            return null;
        }

        $found = [];

        foreach ($this->describeColumns($table) as $row) {
            $name = (string) ($row['name'] ?? $row['column_name'] ?? '');
            $type = strtolower((string) ($row['type'] ?? $row['column_type'] ?? $row['data_type'] ?? ''));

            $isBool = match ($this->driver) {
                'mysql'  => str_starts_with($type, 'tinyint(1)'),
                'pgsql'  => $type === 'boolean',
                'sqlsrv' => $type === 'bit',
            };

            if ($isBool) {
                $found[] = $name;
            }
        }

        sort($found);

        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function describeColumns(string $table): array
    {
        if (preg_match('/^[a-z0-9_]+$/i', $table) !== 1) {
            throw new \InvalidArgumentException('Unsafe table name.');
        }

        return match ($this->driver) {
            'sqlite' => $this->port->query(sprintf('PRAGMA table_info(%s)', $table)),
            'mysql'  => $this->port->query(
                'SELECT column_name, column_type FROM information_schema.columns'
                . ' WHERE table_schema = :db AND table_name = :t ORDER BY ordinal_position',
                ['db' => $this->name, 't' => $table],
            ),
            'pgsql'  => $this->port->query(
                'SELECT column_name, data_type FROM information_schema.columns'
                . ' WHERE table_catalog = :db AND table_schema = \'public\' AND table_name = :t'
                . ' ORDER BY ordinal_position',
                ['db' => $this->name, 't' => $table],
            ),
            'sqlsrv' => $this->port->query(
                'SELECT column_name, data_type FROM information_schema.columns'
                . ' WHERE table_catalog = :db AND table_name = :t ORDER BY ordinal_position',
                ['db' => $this->name, 't' => $table],
            ),
        };
    }

    /**
     * Remove exactly the database this object created.
     *
     * Named positively and guarded by the prefix: nothing is enumerated, and
     * nothing is removed because it failed to match some exclusion.
     */
    public function destroy(): void
    {
        if (!str_starts_with($this->name, self::PREFIX)) {
            throw new \LogicException('Refusing to drop a database this harness did not create.');
        }

        // Close FIRST. SQLite rewrites the -wal and -shm sidecars when the
        // handle closes, so unlinking them while the PDO is still open leaves
        // both files behind — which is how a thousand of them once piled up.
        if (method_exists($this->port, 'close')) {
            $this->port->close();
        }

        if ($this->driver === 'sqlite') {
            foreach (['', '-wal', '-shm'] as $suffix) {
                $file = $this->path . $suffix;

                if (is_file($file)) {
                    @unlink($file);
                }
            }

            return;
        }

        $name   = $this->name;
        $driver = $this->driver;

        self::withAdminConnection($driver, $this->server, static function (PDO $pdo) use ($driver, $name): void {
            $quoted = $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';

            if ($driver === 'sqlsrv') {
                // SQL Server refuses to drop a database that still has
                // sessions on it.
                $pdo->exec(sprintf('ALTER DATABASE %s SET SINGLE_USER WITH ROLLBACK IMMEDIATE', $quoted));
            }

            $pdo->exec('DROP DATABASE ' . $quoted);
        });
    }
}
