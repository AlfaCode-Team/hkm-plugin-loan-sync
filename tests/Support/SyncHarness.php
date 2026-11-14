<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanSync\Application\Services\SyncService;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\OperationLog;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\LoanSync\Infrastructure\Persistence\ShardRepository;
use Tests\Unit\Plugins\LoanSync\Support\TestDatabase;

/**
 * A real tenant database on disk, migrated by the real migration runner, with
 * the real adapter in front of it.
 */
final class SyncHarness
{
    public readonly DatabasePort $db;
    public readonly SyncService $sync;
    public readonly TestDatabase $database;

    public function __construct(string $deviceUser = 'user-1')
    {
        // SQLite unless HKM_TEST_DB says otherwise. The same tests then run
        // against MySQL, PostgreSQL or SQL Server without a second suite.
        //
        // The schema is assembled from the PLUGINS that own each table, so
        // this suite tests against the same migrations a deployment runs.
        // Siblings that are not installed are left out: this plugin is the
        // sync engine, and it must be testable without the whole loan set.
        $this->database = TestDatabase::provision('loan_sync', TestDatabase::available([
            \Plugins\LoanCustomers\Provider::class,
            \Plugins\LoanOrganisation\Provider::class,
            \Plugins\LoanLoans\Provider::class,
            \Plugins\LoanRepayments\Provider::class,
            \Plugins\LoanCash\Provider::class,
            \Plugins\LoanExpenses\Provider::class,
            \Plugins\LoanField\Provider::class,
            \Plugins\LoanSync\Provider::class,
        ]));
        $this->db = $this->database->port;

        $identity = new Identity(
            userId:      $deviceUser,
            tenantId:    'tenant-test',
            roles:       ['officer'],
            permissions: [],
            tokenType:   'jwt',
        );

        $this->sync = new SyncService(
            entities:    new EntityRepository($this->db),
            revisions:   new RevisionRepository($this->db),
            operations:  new OperationLog($this->db),
            shards:      new ShardRepository($this->db),
            devices:     new DeviceRepository($this->db),
            transaction: new TransactionManager($this->db),
            identity:    $identity,
        );
    }

    /** @param array<string, mixed> $fields */
    public static function op(
        string $opId,
        string $entity,
        string $action,
        int $id,
        array $fields = [],
        ?int $baseRev = null,
    ): array {
        $op = [
            'op_id'  => $opId,
            'entity' => $entity,
            'action' => $action,
            'id'     => $id,
            'fields' => $fields,
        ];

        if ($baseRev !== null) {
            $op['base_rev'] = $baseRev;
        }

        return $op;
    }

    /** A customer row with the columns the table actually requires. */
    public static function customer(string $name = 'Aminah Nakato'): array
    {
        return [
            'customer_number' => 'C-' . random_int(1000, 9999),
            'first_name'      => explode(' ', $name)[0],
            'last_name'       => explode(' ', $name)[1] ?? 'X',
            'full_name'       => $name,
            'phone'           => '0772000000',
            'branch_id'       => 1,
            'status'          => 'active',
            'registered_on'   => '2026-11-13',
        ];
    }

    public function destroy(): void
    {
        $this->database->destroy();
    }
}
