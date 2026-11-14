<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * The server's verdict on one operation, and what gets stored so a retry of the
 * same `op_id` can be answered without touching the row again.
 */
final readonly class OperationOutcome
{
    private function __construct(
        public string $opId,
        public OperationStatus $status,
        public ?int $rev,
        public ?string $error,
        /** @var list<string> */
        public array $ignoredFields,
        public ?int $serverRev,
    ) {
    }

    /** @param list<string> $ignored */
    public static function applied(string $opId, int $rev, array $ignored = []): self
    {
        return new self($opId, OperationStatus::Applied, $rev, null, $ignored, null);
    }

    public static function rejected(string $opId, string $why): self
    {
        return new self($opId, OperationStatus::Rejected, null, $why, [], null);
    }

    /**
     * The device wrote against a rev the server has moved past. It is told the
     * rev the server is actually at, so it can pull that row and decide again
     * with the newer facts in hand.
     */
    public static function conflict(string $opId, int $serverRev): self
    {
        return new self(
            $opId,
            OperationStatus::Conflict,
            null,
            'The server copy has changed since this device last saw it.',
            [],
            $serverRev,
        );
    }

    /** Repeat a verdict already recorded for this op_id. */
    public static function replayed(string $opId, ?int $rev, ?string $error): self
    {
        return new self($opId, OperationStatus::Replayed, $rev, $error, [], null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'op_id'  => $this->opId,
            'status' => $this->status->value,
        ];

        if ($this->rev !== null) {
            $out['rev'] = $this->rev;
        }

        if ($this->error !== null) {
            $out['error'] = $this->error;
        }

        if ($this->ignoredFields !== []) {
            $out['ignored_fields'] = $this->ignoredFields;
        }

        if ($this->serverRev !== null) {
            $out['server_rev'] = $this->serverRev;
        }

        return $out;
    }
}
