<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/** A stored attachment opened for streaming back to a handset. */
final readonly class AttachmentFile
{
    /** @param resource $stream the caller closes it */
    public function __construct(
        public mixed $stream,
        public string $mime,
        public int $bytes,
        public string $checksum,
        public string $filename,
    ) {
    }
}
