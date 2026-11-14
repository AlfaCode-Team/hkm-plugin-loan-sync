<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * What a handset may attach, decided here and nowhere else.
 *
 * The MIME allowlist is deliberately short. Every type on it is one a field
 * officer genuinely photographs or scans (an ID card, a signed agreement), and
 * every type OFF it is either active content (SVG, HTML, XML, office macros) or
 * simply not needed. Adding a type is a decision about what the server will
 * later serve, so it belongs in review, not in configuration.
 *
 * The declared Content-Type of the upload is never consulted. It is whatever
 * the handset says, and the type recorded is the one the bytes themselves show.
 */
final class AttachmentPolicy
{
    /** Detected MIME type => extension used when the file is served back. */
    public const array MIME_EXTENSIONS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Entity names and kinds: lowercase slugs, so they are safe in a storage path. */
    public const string SLUG = '/^[a-z][a-z0-9_]{0,47}$/';

    public static function bareMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    public static function isAllowedMime(string $mime): bool
    {
        return array_key_exists(self::bareMime($mime), self::MIME_EXTENSIONS);
    }

    public static function extensionFor(string $mime): ?string
    {
        return self::MIME_EXTENSIONS[self::bareMime($mime)] ?? null;
    }

    public static function isChecksum(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    public static function isSlug(string $value): bool
    {
        return preg_match(self::SLUG, $value) === 1;
    }
}
