<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * How one pushed operation ended.
 *
 * `Replayed` is NOT a fourth outcome of applying a write — it is what the
 * server answers when it recognises an `op_id` it has already settled. The
 * stored verdict is returned verbatim and nothing is written. A phone that
 * retried a push over a dropped connection gets the same answer as the first
 * time, which is the whole reason a retried payment does not become two
 * payments.
 */
enum OperationStatus: string
{
    /** The write was applied and carries a rev. */
    case Applied = 'applied';

    /** Refused, and retrying it unchanged will not help. */
    case Rejected = 'rejected';

    /** The server's copy is newer; the device must pull and re-decide. */
    case Conflict = 'conflict';

    /** This op_id was already settled; the stored verdict is being repeated. */
    case Replayed = 'replayed';

    /** Whether the device should drop this entry from its outbox. */
    public function isSettled(): bool
    {
        return $this !== self::Conflict;
    }
}
