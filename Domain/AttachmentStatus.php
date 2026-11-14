<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * Every answer an attachment upload or download can give.
 *
 * The HTTP mapping lives here, in one place, so the controller cannot drift from
 * the service: a refusal the handset must retry (Unavailable) and a refusal it
 * must never retry (Infected) are different codes on purpose.
 */
enum AttachmentStatus: string
{
    /** New bytes stored and the row marked uploaded. */
    case Stored = 'stored';

    /** The same attachment, the same bytes, already stored. Nothing was written. */
    case Replayed = 'replayed';

    /** Metadata or request shape is wrong. Fix the request and resend. */
    case Invalid = 'invalid';

    /** The bytes do not hash to the checksum the handset declared: a damaged transfer. */
    case ChecksumMismatch = 'checksum_mismatch';

    /** Over the configured size cap. */
    case TooLarge = 'too_large';

    /** Not a type the server accepts (detected from the bytes, not the header). */
    case UnsupportedType = 'unsupported_type';

    /** The sanitiser refused the file: it cannot be made safe to store. */
    case Rejected = 'rejected';

    /** The scanner found a threat. Never retry the same bytes. */
    case Infected = 'infected';

    /** A safety check could not run to a conclusion. Nothing was stored; retry later. */
    case Unavailable = 'unavailable';

    /** The attachment id already holds a different file or belongs to a different record. */
    case Conflict = 'conflict';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Stored           => 201,
            self::Replayed         => 200,
            self::Invalid,
            self::ChecksumMismatch,
            self::Rejected,
            self::Infected         => 422,
            self::TooLarge         => 413,
            self::UnsupportedType  => 415,
            self::Unavailable      => 503,
            self::Conflict         => 409,
        };
    }
}
