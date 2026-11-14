<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * The outcome of one upload. Carries the stored row's public fields on success
 * and a reason the handset can show on refusal, never the scanner's signature
 * name or the server path.
 */
final readonly class AttachmentResult
{
    /**
     * @param array<string, mixed>  $attachment public fields of the row, on Stored and Replayed
     * @param array<string, string> $fields     per-field messages, on Invalid
     */
    public function __construct(
        public AttachmentStatus $status,
        public string $message = '',
        public array $fields = [],
        public array $attachment = [],
    ) {
    }

    public function httpStatus(): int
    {
        return $this->status->httpStatus();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->status === AttachmentStatus::Stored || $this->status === AttachmentStatus::Replayed) {
            return ['status' => $this->status->value, 'attachment' => $this->attachment];
        }

        $error = ['code' => $this->status->value, 'message' => $this->message];

        if ($this->fields !== []) {
            $error['fields'] = $this->fields;
        }

        return ['error' => $error];
    }
}
