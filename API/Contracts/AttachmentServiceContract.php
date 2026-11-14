<?php

declare(strict_types=1);

namespace Plugins\LoanSync\API\Contracts;

use Plugins\LoanSync\Domain\AttachmentFile;
use Plugins\LoanSync\Domain\AttachmentResult;
use Plugins\LoanSync\Domain\AttachmentUpload;

/**
 * The byte side of sync: a handset uploads a file it captured, and fetches one
 * back when it has lost its own copy.
 *
 * The attachment ROW is still synced through push/pull. This contract moves only
 * the bytes, and the row's `uploaded` flag is what tells every other handset that
 * a file is now available to fetch.
 */
interface AttachmentServiceContract
{
    /**
     * Admit, store and record one file. Idempotent on the attachment id: a
     * retry of an upload that already landed changes nothing and takes no rev.
     */
    public function upload(string $deviceId, AttachmentUpload $upload): AttachmentResult;

    /**
     * Open a stored attachment for streaming, or null when there is no such
     * attachment, it was never uploaded, or it has been tombstoned.
     */
    public function open(string $deviceId, int $attachmentId): ?AttachmentFile;
}
