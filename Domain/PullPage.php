<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * One page of a delta pull.
 *
 * THE CURSOR RULE, and it is the one thing in this module that must not be
 * "improved" into something simpler:
 *
 *     `cursor` is the highest rev ACTUALLY INCLUDED in this page — never the
 *     server's current revision, and never `cursor + limit`.
 *
 * Advancing a device past a rev it was not handed loses that row permanently:
 * the next pull asks for rows above a number it never received, and nothing
 * will ever send the gap again. When a page is cut short — by the limit, or by
 * a row deliberately held back — the cursor stops at the last row that made it
 * into the page, and `hasMore` tells the device to come straight back.
 *
 * When nothing changed, the cursor is returned unmoved. A device that pulls an
 * empty page must not conclude it is up to date with the server's latest rev.
 */
final readonly class PullPage
{
    public function __construct(
        /** @var array<string, list<array<string, mixed>>> entity => rows */
        public array $changes,
        public int $cursor,
        public bool $hasMore,
        public int $count,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'changes'  => (object) $this->changes,
            'cursor'   => $this->cursor,
            'has_more' => $this->hasMore,
            'count'    => $this->count,
        ];
    }
}
