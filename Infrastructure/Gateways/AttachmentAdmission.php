<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Gateways;

use Plugins\LoanSync\Domain\AttachmentStatus;

/**
 * The vault's verdict on one staged file: either cleared, with the path of the
 * SANITISED bytes that the scanner read and that may now be stored, or refused,
 * with the status and a reason safe to show the uploader.
 */
final readonly class AttachmentAdmission
{
    private function __construct(
        public ?AttachmentStatus $refusal,
        public string $reason,
        public string $mime,
        public ?string $stagedPath,
        /** Size of the bytes that will be stored: the sanitiser may have changed them. */
        public int $bytes,
    ) {
    }

    public static function cleared(string $mime, string $stagedPath, int $bytes): self
    {
        return new self(null, '', $mime, $stagedPath, $bytes);
    }

    public static function refused(AttachmentStatus $status, string $reason, string $mime = ''): self
    {
        return new self($status, $reason, $mime, null, 0);
    }

    public function isCleared(): bool
    {
        return $this->refusal === null;
    }
}
