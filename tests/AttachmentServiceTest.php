<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ScanVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\LoanSync\Application\Services\AttachmentService;
use Plugins\LoanSync\Domain\AttachmentResult;
use Plugins\LoanSync\Domain\AttachmentStatus;
use Plugins\LoanSync\Domain\AttachmentUpload;
use Tests\Unit\Plugins\LoanSync\Support\AttachmentHarness;
use Tests\Unit\Plugins\LoanSync\Support\SyncHarness;

#[CoversClass(AttachmentService::class)]
final class AttachmentServiceTest extends TestCase
{
    private const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private ?AttachmentHarness $harness = null;

    /** @var list<string> */
    private array $sources = [];

    protected function tearDown(): void
    {
        // Positively identified: only what this test created.
        $this->harness?->destroy();
        $this->harness = null;

        foreach ($this->sources as $path) {
            @unlink($path);
        }

        $this->sources = [];
    }

    private function harness(int $maxBytes = 8_388_608): AttachmentHarness
    {
        return $this->harness ??= new AttachmentHarness($maxBytes);
    }

    /** Stage bytes exactly as the request layer would: a temp file on disk. */
    private function source(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'akasente-test-src-');
        file_put_contents($path, $bytes);
        $this->sources[] = $path;

        return $path;
    }

    /** @param array<string, string> $overrides */
    private function upload(AttachmentHarness $h, string $bytes, array $overrides = [], ?int $size = null): AttachmentResult
    {
        $input = $overrides + [
            'id'          => '1500001',
            'entity'      => 'customer',
            'entity_id'   => '1000001',
            'kind'        => 'id_front',
            'checksum'    => hash('sha256', $bytes),
            'captured_at' => '2026-11-14T08:30:00+03:00',
        ];

        return $h->attachments->upload('device-a', AttachmentUpload::fromInput(
            $input,
            $this->source($bytes),
            $size ?? strlen($bytes),
        ));
    }

    private function row(AttachmentHarness $h, int $id): ?array
    {
        return $h->sync->db->queryOne('SELECT * FROM attachments WHERE id = :id', ['id' => $id]);
    }

    public function test_a_clean_upload_is_stored_marked_uploaded_and_takes_a_rev(): void
    {
        $h      = $this->harness();
        $before = $h->revisions->current();

        $result = $this->upload($h, self::PDF);

        self::assertSame(AttachmentStatus::Stored, $result->status);
        self::assertSame(201, $result->httpStatus());
        self::assertSame($before + 1, $h->revisions->current(), 'the write takes exactly one rev');

        $row = $this->row($h, 1500001);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['uploaded']);
        self::assertSame($before + 1, (int) $row['rev'], 'the row carries the rev so handsets pull it');
        self::assertSame('application/pdf', $row['mime']);
        self::assertSame(strlen(self::PDF), (int) $row['bytes']);
        self::assertSame(hash('sha256', self::PDF), $row['checksum']);
        self::assertSame('device-a', $row['uploaded_from_device']);
        self::assertStringStartsWith('tenant-test/attachments/customer/1000001/', (string) $row['path']);
        self::assertTrue($h->storedFileExists((string) $row['path']));
    }

    /**
     * Server-owned columns cannot be rewritten by a handset through a push. This is
     * the reason path, checksum, uploaded and friends left the client allowlist.
     */
    public function test_a_handset_cannot_claim_or_rewrite_an_upload_through_a_push(): void
    {
        $h = $this->harness();

        $this->upload($h, self::PDF, ['id' => '1500003']);
        $real = $this->row($h, 1500003);

        $out = $h->sync->sync->push('device-b', [
            SyncHarness::op('op-forge', 'attachments', 'update', 1500003, [
                'uploaded'             => 0,
                'path'                 => '/etc/passwd',
                'checksum'             => 'forged',
                'mime'                 => 'image/png',
                'bytes'                => 5,
                'uploaded_from_device' => 'someone-elses-phone',
                'kind'                 => 'id_front',
            ]),
        ]);

        self::assertSame('applied', $out['results'][0]['status']);
        self::assertContains('uploaded', $out['results'][0]['ignored_fields']);
        self::assertContains('path', $out['results'][0]['ignored_fields']);
        self::assertContains('checksum', $out['results'][0]['ignored_fields']);

        $row = $this->row($h, 1500003);
        self::assertSame($real['path'], $row['path'], 'the stored path is untouched');
        self::assertSame($real['checksum'], $row['checksum'], 'the checksum is untouched');
        self::assertSame(1, (int) $row['uploaded'], 'the uploaded flag is untouched');
        self::assertSame('device-a', $row['uploaded_from_device'], 'attribution is untouched');
    }

    public function test_a_stored_attachment_streams_back_and_an_unuploaded_or_deleted_one_does_not(): void
    {
        $h = $this->harness();

        $this->upload($h, self::PDF);

        $file = $h->attachments->open('device-b', 1500001);

        self::assertNotNull($file);
        self::assertSame(self::PDF, stream_get_contents($file->stream));
        fclose($file->stream);
        self::assertSame('application/pdf', $file->mime);
        self::assertSame(strlen(self::PDF), $file->bytes);
        self::assertSame(hash('sha256', self::PDF), $file->checksum);
        self::assertSame('id_front-1500001.pdf', $file->filename);

        // Tombstoned: the row stays for the sync, but the bytes are no longer served.
        $h->sync->sync->push('device-a', [SyncHarness::op('op-del', 'attachments', 'delete', 1500001)]);
        self::assertNull($h->attachments->open('device-b', 1500001));

        self::assertNull($h->attachments->open('device-b', 9999999), 'an id that never existed');
    }
}
