<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanSync\API\Contracts\AttachmentServiceContract;
use Plugins\LoanSync\Domain\AttachmentFile;
use Plugins\LoanSync\Domain\AttachmentResult;
use Plugins\LoanSync\Domain\AttachmentStatus;
use Plugins\LoanSync\Domain\AttachmentUpload;

/**
 * The two byte endpoints. Kept separate from {@see SyncController} so the sync
 * routes that never touch a file do not pay for storage and scanning at load.
 */
final class AttachmentController
{
    private const string DEVICE_HEADER = 'X-Device-Id';

    public function __construct(
        private readonly AttachmentServiceContract $attachments,
    ) {
    }

    public function store(Request $request): Response
    {
        $file = $request->file('file');

        if ($file === null || !$file->isValid()) {
            return Response::unprocessable(['file' => 'Send the file as the "file" field.']);
        }

        $upload = AttachmentUpload::fromInput(
            $request->only(['id', 'entity', 'entity_id', 'kind', 'checksum', 'captured_at']),
            $file->tempPath(),
            $file->size(),
        );

        return $this->respond($this->attachments->upload($this->deviceId($request), $upload));
    }

    public function show(Request $request, string $id): Response
    {
        $file = $this->attachments->open($this->deviceId($request), (int) $id);

        return $file === null ? Response::notFound('Attachment not found.') : $this->stream($file);
    }

    private function respond(AttachmentResult $result): Response
    {
        $response = Response::json($result->toArray(), $result->httpStatus());

        // A refusal the handset should retry gets a hint; one it must not retry does not.
        return $result->status === AttachmentStatus::Unavailable
            ? $response->withHeader('Retry-After', '60')
            : $response;
    }

    private function stream(AttachmentFile $file): Response
    {
        return Response::stream(
            static function () use ($file): void {
                fpassthru($file->stream);
                fclose($file->stream);
            },
            200,
            [
                'Content-Type'           => $file->mime,
                'Content-Length'         => (string) $file->bytes,
                'Content-Disposition'    => 'attachment; filename="' . $file->filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'private, no-store',
                'X-Attachment-Checksum'  => $file->checksum,
            ],
        );
    }

    private function deviceId(Request $request): string
    {
        return trim((string) ($request->header(self::DEVICE_HEADER) ?? ''));
    }
}
