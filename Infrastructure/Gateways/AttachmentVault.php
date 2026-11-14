<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\FileSanitizerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\FileScannerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use Plugins\LoanSync\Domain\AttachmentPolicy;
use Plugins\LoanSync\Domain\AttachmentStatus;

/**
 * The only class that handles attachment BYTES. It sits between the untrusted
 * upload and the servable store, and owns the order of the three checks:
 *
 *   1. detect the type from the bytes, refuse anything not on the allowlist;
 *   2. sanitise in memory (UploadGuard), refuse what cannot be made safe;
 *   3. stage the sanitised bytes to a private temp file and SCAN that file
 *      (ClamAV) — the scanner needs a path, so the bytes exist on local disk
 *      for the length of the scan, but only in a 0600 temp file that is not
 *      under the storage root and is never served;
 *   4. only then persist() streams the SAME staged file into StoragePort.
 *
 * Nothing reaches StoragePort unless step 3 returned a clean verdict. A refusal
 * at any step leaves the store untouched.
 *
 * ─── WHY AN UNREACHABLE SCANNER IS A REFUSAL, NOT A QUARANTINE ──────────────
 *
 * The scanner's Unavailable verdict is treated as "not yet known", and the
 * upload is refused with 503. The alternatives were weighed:
 *
 *   - Treat it as clean: a clamd restart becomes an open door. Rejected.
 *   - Treat it as infected: every photo taken during a clamd restart is lost.
 *     Rejected, and the verdict does not say the file is bad.
 *   - Quarantine (store the bytes under a non-served path with a pending flag,
 *     scan later): the attachments table has no scan-status column, so the
 *     quarantine would have nowhere to record that it is pending. Adding one is
 *     a schema change this slice does not make. And it puts unscanned,
 *     untrusted bytes into storage, which is the thing the scan exists to
 *     prevent.
 *   - Refuse (chosen): nothing is stored, the row stays uploaded=0, and the
 *     handset STILL HOLDS THE ONLY COPY and retries. Nothing is lost, nothing
 *     servable exists, and the cost is a delay, which is exactly what the
 *     FileScannerPort docblock recommends.
 *
 * A sanitiser or scanner that THROWS is treated the same way: the port says a
 * throw means the caller cannot know whether the file is safe, so the file is
 * refused and is not stored.
 */
final class AttachmentVault
{
    public function __construct(
        private readonly StoragePort $storage,
        private readonly FileSanitizerPort $sanitizer,
        private readonly FileScannerPort $scanner,
        private readonly ?LoggerPort $logger = null,
    ) {
    }

    /** Detect, sanitise and scan. The bytes are never written to the store here. */
    public function admit(string $contents): AttachmentAdmission
    {
        $mime = $this->detect($contents);

        if (!AttachmentPolicy::isAllowedMime($mime)) {
            return AttachmentAdmission::refused(
                AttachmentStatus::UnsupportedType,
                'This file type is not accepted.',
                $mime,
            );
        }

        try {
            $sanitised = $this->sanitizer->handles($mime)
                ? $this->sanitizer->sanitize($contents, $mime)
                : null;
        } catch (\Throwable $e) {
            $this->logger?->error('sync.attachment.sanitizer_failed', ['mime' => $mime, 'error' => $e->getMessage()]);

            return AttachmentAdmission::refused(
                AttachmentStatus::Unavailable,
                'The file could not be checked. Try again shortly.',
                $mime,
            );
        }

        if ($sanitised !== null && $sanitised->isRejected()) {
            return AttachmentAdmission::refused(
                AttachmentStatus::Rejected,
                $sanitised->reason() !== '' ? $sanitised->reason() : 'This file could not be made safe to store.',
                $mime,
            );
        }

        // Store and scan exactly what the sanitiser produced — never the bytes
        // the handset sent, if they differ.
        $safe = $sanitised?->contents() ?? $contents;

        if ($sanitised !== null && $sanitised->wasModified()) {
            $this->logger?->info('sync.attachment.sanitised', ['mime' => $mime, 'removed' => $sanitised->removed()]);
        }

        $staged = $this->stage($safe);

        if ($staged === null) {
            return AttachmentAdmission::refused(
                AttachmentStatus::Unavailable,
                'The file could not be checked. Try again shortly.',
                $mime,
            );
        }

        try {
            $verdict = $this->scanner->scan($staged);
        } catch (\Throwable $e) {
            @unlink($staged);
            $this->logger?->error('sync.attachment.scanner_threw', ['error' => $e->getMessage()]);

            return AttachmentAdmission::refused(
                AttachmentStatus::Unavailable,
                'The file could not be checked. Try again shortly.',
                $mime,
            );
        }

        if ($verdict->isInfected()) {
            @unlink($staged);
            // The signature name goes to the log for the operator. The uploader
            // gets no hint of which payload was caught (see ScanVerdict).
            $this->logger?->warning('sync.attachment.infected', ['threat' => $verdict->threat(), 'mime' => $mime]);

            return AttachmentAdmission::refused(AttachmentStatus::Infected, 'This file was refused.', $mime);
        }

        // Only an explicit Clean clears a file. Pending, and anything the port
        // ever adds, is withheld.
        if (!$verdict->isClean()) {
            @unlink($staged);
            $this->logger?->warning('sync.attachment.scan_inconclusive', ['reason' => $verdict->reason()]);

            return AttachmentAdmission::refused(
                AttachmentStatus::Unavailable,
                'The file could not be checked. Try again shortly.',
                $mime,
            );
        }

        return AttachmentAdmission::cleared($mime, $staged, strlen($safe));
    }

    /**
     * Stream the staged, scanned bytes into storage. Only call on a cleared admission.
     *
     * @return string the path the storage adapter returned
     */
    public function persist(AttachmentAdmission $admission, string $folder, string $filename): string
    {
        if (!$admission->isCleared() || $admission->stagedPath === null) {
            throw new \LogicException('Only a cleared attachment may be persisted.');
        }

        $handle = fopen($admission->stagedPath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('The staged attachment could not be reopened.');
        }

        try {
            return $this->storage->storeStream($handle, $filename, $folder, 'private');
        } finally {
            fclose($handle);
        }
    }

    /** Remove the staged temp file. Always call, on every path. */
    public function discard(AttachmentAdmission $admission): void
    {
        if ($admission->stagedPath !== null) {
            @unlink($admission->stagedPath);
        }
    }

    /** @return resource|null */
    public function open(string $path)
    {
        return $this->storage->readStream($path);
    }

    /**
     * Where a stored attachment lives. Derived from validated parts only, so no
     * request value can name a path outside this tenant's attachments folder.
     *
     * @return array{0: string, 1: string} [folder, filename]
     */
    public function location(string $tenantId, string $entity, int $entityId, int $attachmentId, string $checksum, string $mime): array
    {
        $tenant = preg_replace('/[^A-Za-z0-9_-]/', '', $tenantId) ?: 'default';
        $ext    = AttachmentPolicy::extensionFor($mime) ?? 'bin';

        return [
            sprintf('%s/attachments/%s/%d', $tenant, $entity, $entityId),
            sprintf('%d-%s.%s', $attachmentId, substr($checksum, 0, 12), $ext),
        ];
    }

    private function detect(string $contents): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return AttachmentPolicy::bareMime((string) $finfo->buffer($contents));
    }

    /** Write the sanitised bytes to a 0600 temp file outside the storage root. */
    private function stage(string $contents): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'akasente-att-');

        if ($path === false) {
            return null;
        }

        if (file_put_contents($path, $contents) !== strlen($contents)) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}
