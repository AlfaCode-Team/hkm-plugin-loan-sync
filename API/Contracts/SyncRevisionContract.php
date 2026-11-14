<?php

declare(strict_types=1);

namespace Plugins\LoanSync\API\Contracts;

/**
 * Hands out the next revision for this tenant.
 *
 * WHY EVERY DOMAIN MODULE NEEDS THIS
 * ----------------------------------
 * `rev` is the delta cursor. A row written WITHOUT taking a new rev is a row no
 * handset will ever pull: the device asks for everything above its cursor, and
 * an un-stamped row is not above anything. It simply never arrives.
 *
 * That makes this the single most dangerous thing to forget in a module that
 * writes to a synced table. The failure is silent on the server — the row is
 * right there in the database — and shows up as a branch manager insisting
 * they changed a customer's phone number while every field officer's phone
 * shows the old one.
 *
 * So: a web edit to a synced table takes a rev, exactly like a device push
 * does. Same counter, same ordering, same guarantee.
 *
 * MUST be called inside a transaction, with the write it stamps. The
 * implementation refuses otherwise, because a rev issued outside one releases
 * the lock that keeps revisions committing in order.
 */
interface SyncRevisionContract
{
    /**
     * Reserve the next revision.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException
     *         when called outside a transaction, or the driver cannot be named
     */
    public function next(): int;

    /** The newest revision this tenant has issued. */
    public function current(): int;
}
