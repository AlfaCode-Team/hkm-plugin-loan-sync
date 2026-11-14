<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * A device's exclusive slice of the id space.
 *
 * Ids are minted on the handset as `shard * SPAN + counter`, so two devices can
 * both be offline for a week and still never mint the same id. The claim is
 * what turns "probably unique" into unique, and "probably" is not a property
 * you want in a number printed on a customer's receipt.
 *
 * Shard 0 is reserved for the legacy range — every id that existed before
 * sharding, including everything the Firestore import brings over. It is never
 * handed out.
 */
final readonly class ShardClaim
{
    /**
     * THE ID SPACE, PARTITIONED. Every minted id in the system falls in exactly
     * one of these three windows, and the partition is what makes the whole
     * offline design work without a central allocator.
     *
     *   shard 0            LEGACY — everything minted before sharding, and
     *                      everything the Firestore import brings over. Never
     *                      handed to a device, never minted into by the server.
     *   shards 1..999_998  DEVICES — one per handset, claimed from `id_shards`.
     *   shard 999_999      THE SERVER — rows created on the web.
     *
     * The server needs a window of its OWN, and that is not fussiness. Web
     * rows used to be minted as MAX(id)+1 inside the legacy range, which is
     * the same range the Firestore import writes into. Create a loan on the
     * web today, import a v1 loan carrying that id next week, and the two land
     * on each other — a real record silently overwritten by another real
     * record, with no error and nothing to notice afterwards.
     */
    public const int SPAN       = 1_000_000;
    public const int LEGACY     = 0;
    public const int FIRST      = 1;
    public const int LAST       = 999_998;
    public const int SERVER     = 999_999;

    /** The first id the SERVER may mint (web-created rows). */
    public const int SERVER_FLOOR   = self::SERVER * self::SPAN;

    /** The last id the SERVER may mint. */
    public const int SERVER_CEILING = self::SERVER_FLOOR + self::SPAN - 1;

    public function __construct(
        public int $shard,
        public string $deviceId,
        public bool $freshlyClaimed,
    ) {
    }

    /** The first id this shard can mint. */
    public function floor(): int
    {
        return $this->shard * self::SPAN;
    }

    /** The last id this shard can mint. */
    public function ceiling(): int
    {
        return $this->floor() + self::SPAN - 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'shard'           => $this->shard,
            'device_id'       => $this->deviceId,
            'id_floor'        => $this->floor(),
            'id_ceiling'      => $this->ceiling(),
            'span'            => self::SPAN,
            'freshly_claimed' => $this->freshlyClaimed,
        ];
    }
}
