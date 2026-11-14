<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanSync\API\Contracts\AttachmentServiceContract;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;
use Plugins\LoanSync\Domain\AttachmentFile;
use Plugins\LoanSync\Domain\AttachmentPolicy;
use Plugins\LoanSync\Domain\AttachmentResult;
use Plugins\LoanSync\Domain\AttachmentStatus;
use Plugins\LoanSync\Domain\AttachmentUpload;
use Plugins\LoanSync\Domain\EntityCatalogue;
use Plugins\LoanSync\Infrastructure\Gateways\AttachmentAdmission;
use Plugins\LoanSync\Infrastructure\Gateways\AttachmentVault;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;

/**
 * Admits an uploaded attachment, records it, and serves it back.
 *
 * ─── THE ORDER, AND WHY ────────────────────────────────────────────────────
 *
 *  1. Validate the metadata and the size, before reading the bytes.
 *  2. Read the bytes and check them against the checksum the handset declared.
 *     A mismatch is a damaged transfer, not a malicious file: refuse, nothing else.
 *  3. IDEMPOTENCY, before any scanning. A row already uploaded with the same
 *     checksum is answered as a replay. A retry after a dropped connection costs
 *     one hash and no scan, no storage write and no rev.
 *  4. Admit through the vault: type, sanitise, scan. Nothing is stored until
 *     this clears.
 *  5. Persist the bytes to storage, then, in ONE transaction, take a rev and
 *     write the row. The rev is taken inside the transaction as the sync
 *     contract requires, so a handset cannot pull a row whose file is not yet
 *     there: the row only becomes visible when its bytes already exist.
 *
 * Step 5 re-reads the row inside the transaction. Two devices uploading the same
 * id concurrently both pass step 3; the rev reservation serialises them, and the
 * second sees the first's committed row and replays instead of inserting a
 * duplicate.
 *
 * ─── WHAT IS NOT DELETED ON FAILURE ─────────────────────────────────────────
 *
 * If persist() succeeds and the transaction then fails, the stored file is left
 * behind. It is harmless: nothing references it, and nothing serves a path that
 * no row names. Deleting it would be unsafe, because the storage path is derived
 * from (id, checksum), so a concurrent identical upload may have just written the
 * SAME path for a row that committed. An orphan costs bytes; a deleted live file
 * costs a field officer's photo of a national ID.
 */
final class AttachmentService implements AttachmentServiceContract
{
    private const string TABLE = 'attachments';

    public function __construct(
        private readonly EntityRepository $entities,
        private readonly SyncRevisionContract $revisions,
        private readonly DeviceRepository $devices,
        private readonly TransactionManager $transaction,
        private readonly Identity $identity,
        private readonly AttachmentVault $vault,
        private readonly int $maxBytes,
        private readonly ?LoggerPort $logger = null,
    ) {
    }

    public function upload(string $deviceId, AttachmentUpload $upload): AttachmentResult
    {
        $this->guardDevice($deviceId);

        $metadata = $this->validate($upload);

        if ($metadata instanceof AttachmentResult) {
            return $metadata;
        }

        [$id, $entityId, $capturedAt] = $metadata;

        if ($upload->size <= 0) {
            return $this->invalid(['file' => 'The file is empty.']);
        }

        if ($upload->size > $this->maxBytes) {
            return $this->tooLarge();
        }

        $contents = $this->readBounded($upload->sourcePath);

        if ($contents === null) {
            return $this->tooLarge();
        }

        if ($contents === false) {
            return $this->invalid(['file' => 'The file could not be read.']);
        }

        $actual = hash('sha256', $contents);

        if (!hash_equals($upload->checksum, $actual)) {
            return new AttachmentResult(
                AttachmentStatus::ChecksumMismatch,
                'The file does not match its checksum. Send it again.',
            );
        }

        // Step 3: a settled attachment is answered from the row, before any work.
        $existing = $this->entities->find($this->catalogue(), $id);

        if ($existing !== null) {
            $settled = $this->judge($existing, $upload, $entityId, $actual);

            if ($settled !== null) {
                return $settled;
            }
        }

        // Step 4.
        $admission = $this->vault->admit($contents);

        if (!$admission->isCleared()) {
            return $this->refused($admission);
        }

        try {
            [$folder, $filename] = $this->vault->location(
                $this->identity->tenantId,
                $upload->entity,
                $entityId,
                $id,
                $actual,
                $admission->mime,
            );

            // Step 5a: bytes first, so a row is never visible without its file.
            $storedPath = $this->vault->persist($admission, $folder, $filename);

            return $this->record($deviceId, $upload, $id, $entityId, $capturedAt, $storedPath, $admission, $actual);
        } catch (\Throwable $e) {
            $this->logger?->error('sync.attachment.failed', ['attachment_id' => $id, 'error' => $e->getMessage()]);

            throw new ServiceException(
                'The attachment could not be stored.',
                layer: 'service.sync.attachment',
                context: ['attachment_id' => $id],
                previous: $e,
            );
        } finally {
            $this->vault->discard($admission);
        }
    }

    public function open(string $deviceId, int $attachmentId): ?AttachmentFile
    {
        $this->guardDevice($deviceId);

        $row = $this->entities->find($this->catalogue(), $attachmentId);

        // A tombstoned row or one whose bytes never arrived has nothing to serve.
        if ($row === null || ($row['deleted_at'] ?? null) !== null || (int) ($row['uploaded'] ?? 0) !== 1) {
            return null;
        }

        $mime = (string) ($row['mime'] ?? '');

        return new AttachmentFile(
            stream:   $this->vault->open((string) $row['path']),
            mime:     $mime,
            bytes:    (int) ($row['bytes'] ?? 0),
            checksum: (string) ($row['checksum'] ?? ''),
            filename: sprintf('%s-%d.%s', (string) $row['kind'], $attachmentId, AttachmentPolicy::extensionFor($mime) ?? 'bin'),
        );
    }

    /**
     * Metadata checks. Returns the parsed [id, entity_id, captured_at] or the refusal.
     *
     * @return array{0: int, 1: int, 2: ?string}|AttachmentResult
     */
    private function validate(AttachmentUpload $upload): array|AttachmentResult
    {
        $errors = [];

        $id       = $this->positive($upload->attachmentId);
        $entityId = $this->positive($upload->entityId);

        if ($id === null) {
            $errors['id'] = 'The attachment id must be a positive whole number.';
        }

        if ($entityId === null) {
            $errors['entity_id'] = 'The record id must be a positive whole number.';
        }

        if (!AttachmentPolicy::isSlug($upload->entity)) {
            $errors['entity'] = 'The entity must be a lowercase name.';
        }

        if (!AttachmentPolicy::isSlug($upload->kind)) {
            $errors['kind'] = 'The kind must be a lowercase name.';
        }

        if (!AttachmentPolicy::isChecksum($upload->checksum)) {
            $errors['checksum'] = 'The checksum must be a SHA-256 hex digest.';
        }

        $capturedAt = null;

        if ($upload->capturedAt !== '') {
            try {
                $capturedAt = (new \DateTimeImmutable($upload->capturedAt))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $errors['captured_at'] = 'The capture time is not a valid date.';
            }
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        return [$id, $entityId, $capturedAt];
    }

    /**
     * Decide an attachment that already has a row. Null means the row exists but
     * has no bytes yet, so the upload should proceed and fill it in.
     *
     * @param array<string, mixed> $row
     */
    private function judge(array $row, AttachmentUpload $upload, int $entityId, string $checksum): ?AttachmentResult
    {
        $sameParent = (string) ($row['entity'] ?? '') === $upload->entity
            && (int) ($row['entity_id'] ?? 0) === $entityId
            && (string) ($row['kind'] ?? '') === $upload->kind;

        if (!$sameParent) {
            return new AttachmentResult(
                AttachmentStatus::Conflict,
                'That attachment id belongs to a different record.',
            );
        }

        if ((int) ($row['uploaded'] ?? 0) !== 1) {
            return null;
        }

        if (hash_equals((string) ($row['checksum'] ?? ''), $checksum)) {
            return $this->replayed($row);
        }

        return new AttachmentResult(
            AttachmentStatus::Conflict,
            'That attachment id already holds a different file.',
        );
    }

    /**
     * Step 5b: take the rev and write the row, in one transaction.
     */
    private function record(
        string $deviceId,
        AttachmentUpload $upload,
        int $id,
        int $entityId,
        ?string $capturedAt,
        string $storedPath,
        AttachmentAdmission $admission,
        string $checksum,
    ): AttachmentResult {
        $fields = [
            'entity'               => $upload->entity,
            'entity_id'            => $entityId,
            'kind'                 => $upload->kind,
            'path'                 => $storedPath,
            'mime'                 => $admission->mime,
            'bytes'                => $admission->bytes,
            'checksum'             => $checksum,
            'uploaded_from_device' => $deviceId,
            'uploaded'             => 1,
        ];

        if ($capturedAt !== null) {
            $fields['captured_at'] = $capturedAt;
        }

        $this->transaction->begin();

        try {
            $rev = $this->revisions->next();

            // Re-read INSIDE the rev reservation: a concurrent identical upload
            // that committed first is visible here, and is replayed.
            $row = $this->entities->find($this->catalogue(), $id);

            if ($row !== null) {
                $settled = $this->judge($row, $upload, $entityId, $checksum);

                if ($settled !== null) {
                    $this->transaction->rollback();

                    return $settled;
                }

                $this->entities->update($this->catalogue(), $id, $fields, $rev, $deviceId);
            } else {
                $this->entities->insert($this->catalogue(), $id, $fields, $rev, $deviceId);
            }

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $e;
        }

        return new AttachmentResult(AttachmentStatus::Stored, '', [], [
            'id'        => $id,
            'rev'       => $rev,
            'uploaded'  => true,
            'entity'    => $upload->entity,
            'entity_id' => $entityId,
            'kind'      => $upload->kind,
            'mime'      => $admission->mime,
            'bytes'     => $admission->bytes,
            'checksum'  => $checksum,
        ]);
    }

    private function refused(AttachmentAdmission $admission): AttachmentResult
    {
        $status = $admission->refusal ?? AttachmentStatus::Unavailable;

        return new AttachmentResult($status, $admission->reason);
    }

    /** @param array<string, mixed> $row */
    private function replayed(array $row): AttachmentResult
    {
        return new AttachmentResult(AttachmentStatus::Replayed, '', [], [
            'id'        => (int) $row['id'],
            'rev'       => (int) ($row['rev'] ?? 0),
            'uploaded'  => true,
            'entity'    => (string) $row['entity'],
            'entity_id' => (int) $row['entity_id'],
            'kind'      => (string) $row['kind'],
            'mime'      => (string) ($row['mime'] ?? ''),
            'bytes'     => (int) ($row['bytes'] ?? 0),
            'checksum'  => (string) ($row['checksum'] ?? ''),
        ]);
    }

    /** @param array<string, string> $fields */
    private function invalid(array $fields): AttachmentResult
    {
        return new AttachmentResult(AttachmentStatus::Invalid, 'Validation failed.', $fields);
    }

    private function tooLarge(): AttachmentResult
    {
        return new AttachmentResult(
            AttachmentStatus::TooLarge,
            sprintf('The file is larger than the %d-byte limit.', $this->maxBytes),
        );
    }

    /**
     * The bytes, or false when unreadable, or null when over the cap. Reads at
     * most one byte past the cap, so a file that grew since the request reported
     * its size is still refused without being loaded whole.
     */
    private function readBounded(string $path): string|false|null
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $contents = stream_get_contents($handle, $this->maxBytes + 1);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            return false;
        }

        return strlen($contents) > $this->maxBytes ? null : $contents;
    }

    private function positive(string $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }

    private function catalogue(): EntityCatalogue
    {
        return EntityCatalogue::get(self::TABLE);
    }

    private function guardDevice(string $deviceId): void
    {
        if ($deviceId === '' || strlen($deviceId) > 64) {
            throw new ServiceException(
                'A device id of 1 to 64 characters is required.',
                layer: 'service.sync.attachment',
            );
        }

        if ($this->devices->isBlocked($deviceId)) {
            throw new ServiceException(
                'This device has been blocked.',
                layer: 'service.sync.attachment',
                context: ['device_id' => $deviceId],
            );
        }
    }
}
