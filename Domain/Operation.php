<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * One entry from a device's outbox.
 *
 * `opId` is minted by the CLIENT and is the idempotency key. It must survive
 * the app being killed mid-push, so it is generated when the entry is queued,
 * not when it is sent — a retry carries the id the first attempt carried, and
 * that identity is the only thing standing between a flaky connection and a
 * duplicated payment.
 *
 * `baseRev` is the rev the device believed the row was at. It is how a conflict
 * is detected: if the server's row has moved on, the device decided against
 * something that is no longer true.
 */
final readonly class Operation
{
    private function __construct(
        public string $opId,
        public string $entity,
        public SyncAction $action,
        public int $id,
        /** @var array<string, mixed> */
        public array $fields,
        public ?int $baseRev,
    ) {
    }

    /**
     * @param  array<string, mixed> $raw one element of the push payload
     * @throws \DomainException when the envelope is unusable
     */
    public static function fromArray(array $raw): self
    {
        $opId = trim((string) ($raw['op_id'] ?? ''));

        if ($opId === '' || strlen($opId) > 64) {
            throw new \DomainException('Each operation needs an op_id of 1 to 64 characters.');
        }

        $entity = trim((string) ($raw['entity'] ?? ''));

        if (!EntityCatalogue::has($entity)) {
            throw new \DomainException(sprintf('Entity [%s] is not syncable.', $entity));
        }

        $id = $raw['id'] ?? null;

        // A client-minted id must be a positive integer: it was minted from a
        // claimed shard, and anything else means the device skipped the claim.
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new \DomainException('Each operation needs an integer id, minted from a claimed shard.');
        }

        $id = (int) $id;

        if ($id <= 0) {
            throw new \DomainException('A client-minted id must be greater than zero.');
        }

        $fields = $raw['fields'] ?? [];

        if (!is_array($fields)) {
            throw new \DomainException('Operation fields must be an object.');
        }

        $baseRev = $raw['base_rev'] ?? null;

        return new self(
            opId:    $opId,
            entity:  $entity,
            action:  SyncAction::parse((string) ($raw['action'] ?? '')),
            id:      $id,
            fields:  $fields,
            baseRev: $baseRev === null ? null : (int) $baseRev,
        );
    }

    public function catalogue(): EntityCatalogue
    {
        return EntityCatalogue::get($this->entity);
    }
}
