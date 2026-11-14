<?php

declare(strict_types=1);

namespace Plugins\LoanSync;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\FileSanitizerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\FileScannerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\LoanSync\API\Contracts\AttachmentServiceContract;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;
use Plugins\LoanSync\API\Contracts\SyncServiceContract;
use Plugins\LoanSync\Application\Services\AttachmentService;
use Plugins\LoanSync\Application\Services\SyncService;
use Plugins\LoanSync\Infrastructure\Gateways\AttachmentVault;
use Plugins\LoanSync\Infrastructure\Http\Controllers\AttachmentController;
use Plugins\LoanSync\Infrastructure\Http\Controllers\SyncController;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\OperationLog;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\LoanSync\Infrastructure\Persistence\ShardRepository;

/**
 * Sync — owns 'sync.offline'.
 *
 * Every binding is resolved per request, and the DatabasePort it is handed is
 * the REQUEST's — which on a tenancy host is that business's own database. A
 * binding made in the core container would hold the central connection for the
 * life of the worker and serve one business's rows to another.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'sync.offline';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SyncServiceContract::class, SyncRevisionContract::class, AttachmentServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            EntityRepository::class,
            static fn($c): EntityRepository => new EntityRepository($c->make(DatabasePort::class)),
        );

        // PUBLIC, not internal: every domain module that writes to a synced
        // table must take a rev, or its rows never reach a handset.
        $container->bind(
            SyncRevisionContract::class,
            static fn($c): RevisionRepository => new RevisionRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            RevisionRepository::class,
            static fn($c): RevisionRepository => $c->make(SyncRevisionContract::class),
        );

        $container->bindInternal(
            OperationLog::class,
            static fn($c): OperationLog => new OperationLog($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            ShardRepository::class,
            static fn($c): ShardRepository => new ShardRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            DeviceRepository::class,
            static fn($c): DeviceRepository => new DeviceRepository($c->make(DatabasePort::class)),
        );

        $container->bind(
            SyncServiceContract::class,
            static fn($c): SyncService => new SyncService(
                entities:    $c->make(EntityRepository::class),
                revisions:   $c->make(RevisionRepository::class),
                operations:  $c->make(OperationLog::class),
                shards:      $c->make(ShardRepository::class),
                devices:     $c->make(DeviceRepository::class),
                transaction: $c->make(TransactionManager::class),
                identity:    $c->make(Identity::class),
                // Optional: the scaffold binds no LoggerPort in the core
                // container, and a sync that refuses to run because nothing is
                // listening would be worse than one that runs quietly.
                logger:      $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );

        $container->bind(
            SyncController::class,
            static fn($c): SyncController => new SyncController($c->make(SyncServiceContract::class)),
        );

        // Attachment bytes. The three ports below come from the modules the two
        // attachment routes declare in module.json (storage.local,
        // file.sanitization, file.scanning) — and ONLY those routes, so a sync
        // request that never touches a file does not load them.
        $container->bindInternal(
            AttachmentVault::class,
            static fn($c): AttachmentVault => new AttachmentVault(
                storage:   $c->make(StoragePort::class),
                sanitizer: $c->make(FileSanitizerPort::class),
                scanner:   $c->make(FileScannerPort::class),
                logger:    $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );

        $container->bind(
            AttachmentServiceContract::class,
            static fn($c): AttachmentService => new AttachmentService(
                entities:    $c->make(EntityRepository::class),
                revisions:   $c->make(SyncRevisionContract::class),
                devices:     $c->make(DeviceRepository::class),
                transaction: $c->make(TransactionManager::class),
                identity:    $c->make(Identity::class),
                vault:       $c->make(AttachmentVault::class),
                maxBytes:    self::attachmentMaxBytes(),
                logger:      $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );

        $container->bind(
            AttachmentController::class,
            static fn($c): AttachmentController => new AttachmentController($c->make(AttachmentServiceContract::class)),
        );
    }

    /**
     * The largest attachment accepted, in bytes. Kept below the scanner's own
     * ceiling (CLAMAV_MAX_BYTES, 25 MB by default) so a file that passes here is
     * never handed to the scanner over its limit, which would read as an outage.
     */
    private static function attachmentMaxBytes(): int
    {
        return (int) (env('ATTACHMENT_MAX_BYTES') ?: 8_388_608);
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Routes are declared in module.json; nothing to hook.
    }
}
