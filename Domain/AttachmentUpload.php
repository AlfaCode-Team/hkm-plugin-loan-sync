<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * One upload attempt: the metadata the handset sent and the bytes it staged.
 *
 * Every field is still the raw value from the request. Validation happens in
 * the service, so a bad value produces a refusal the handset can read rather
 * than an exception that looks like a server fault.
 */
final readonly class AttachmentUpload
{
    public function __construct(
        public string $attachmentId,
        public string $entity,
        public string $entityId,
        public string $kind,
        public string $checksum,
        public string $capturedAt,
        /** Where PHP staged the multipart file. Read by the service, never served. */
        public string $sourcePath,
        /** Size as the request reported it; re-checked against the bytes read. */
        public int $size,
    ) {
    }

    /** @param array<string, mixed> $input the id, entity, entity_id, kind, checksum and captured_at fields */
    public static function fromInput(array $input, string $sourcePath, int $size): self
    {
        return new self(
            attachmentId: trim((string) ($input['id'] ?? '')),
            entity:       trim((string) ($input['entity'] ?? '')),
            entityId:     trim((string) ($input['entity_id'] ?? '')),
            kind:         trim((string) ($input['kind'] ?? '')),
            checksum:     strtolower(trim((string) ($input['checksum'] ?? ''))),
            capturedAt:   trim((string) ($input['captured_at'] ?? '')),
            sourcePath:   $sourcePath,
            size:         $size,
        );
    }
}
