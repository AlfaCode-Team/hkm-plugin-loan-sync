<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;
use Plugins\LoanSync\Application\Services\AttachmentService;
use Plugins\LoanSync\Infrastructure\Gateways\AttachmentVault;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\UploadGuard\Infrastructure\Detection\ByteSniffer;
use Plugins\UploadGuard\Infrastructure\Sanitizers\GifSanitizer;
use Plugins\UploadGuard\Infrastructure\Sanitizers\JpegSanitizer;
use Plugins\UploadGuard\Infrastructure\Sanitizers\MarkupGuard;
use Plugins\UploadGuard\Infrastructure\Sanitizers\PngSanitizer;
use Plugins\UploadGuard\Infrastructure\Sanitizers\SvgSanitizer;
use Plugins\UploadGuard\Infrastructure\Sanitizers\UploadGuard;
use Plugins\UploadGuard\Infrastructure\Sanitizers\WebpSanitizer;

/**
 * The attachment path, assembled from the REAL pieces:
 *
 *   - the real tenant database, through the real adapter ({@see SyncHarness});
 *   - the real UploadGuard sanitiser, built exactly as its Provider builds it;
 *   - the real LocalStorageAdapter, rooted in a temporary directory this
 *     harness created and removes;
 *   - the scanner, faked at its port ({@see FakeScanner}).
 */
final class AttachmentHarness
{
    public readonly SyncHarness $sync;
    public readonly AttachmentService $attachments;
    public readonly FakeScanner $scanner;
    public readonly RevisionRepository $revisions;
    public readonly EntityRepository $entities;
    public readonly string $root;

    public function __construct(int $maxBytes = 8_388_608)
    {
        $this->sync    = new SyncHarness();
        $this->scanner = new FakeScanner();
        $this->root    = sys_get_temp_dir() . '/akasente-att-root-' . bin2hex(random_bytes(8));

        mkdir($this->root, 0750, true);

        $db = $this->sync->db;

        $sanitizer = new UploadGuard(
            sniffer:        new ByteSniffer(),
            svg:            new SvgSanitizer(allowExternal: false, maxElements: 10_000),
            markup:         new MarkupGuard(htmlPolicy: 'reject', allowExternal: false, maxElements: 10_000),
            jpeg:           new JpegSanitizer(),
            png:            new PngSanitizer(),
            gif:            new GifSanitizer(),
            webp:           new WebpSanitizer(),
            extra:          [],
            maxMarkupBytes: 2_000_000,
        );

        $this->revisions = new RevisionRepository($db);
        $this->entities  = new EntityRepository($db);

        $vault = new AttachmentVault(
            storage:   new LocalStorageAdapter(root: $this->root),
            sanitizer: $sanitizer,
            scanner:   $this->scanner,
        );

        $this->attachments = new AttachmentService(
            entities:    $this->entities,
            revisions:   $this->revisions,
            devices:     new DeviceRepository($db),
            transaction: new TransactionManager($db),
            identity:    new Identity(
                userId:      'user-1',
                tenantId:    'tenant-test',
                roles:       ['officer'],
                permissions: [],
                tokenType:   'jwt',
            ),
            vault:       $vault,
            maxBytes:    $maxBytes,
        );
    }

    /** Count of regular files anywhere under the storage root. */
    public function storedFileCount(): int
    {
        if (!is_dir($this->root)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    public function storedFileExists(string $relativePath): bool
    {
        return is_file($this->root . '/' . $relativePath);
    }

    public function destroy(): void
    {
        // Only the tree this harness created, identified by its own unique name.
        if (is_dir($this->root)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }

            @rmdir($this->root);
        }

        $this->sync->destroy();
    }
}
