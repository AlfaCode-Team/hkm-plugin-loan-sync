<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * The files a phone captured, and whether their bytes have arrived.
 *
 * `path` is NULLABLE on purpose: a handset can record that a photo exists
 * before it has any signal to upload it with. `uploaded` is false until the
 * bytes land, and until then the phone holds the only copy — which is exactly
 * what the column says.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        // Every photo and file the phone captured: ID documents, signed
        // agreements, signatures (photographed, never drawn), proof shots.
        // The row is the record; the bytes live in StoragePort.
        $schema->create('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();          // client-minted
            $table->string('entity', 48);                         // customer | loan | payment | …
            $table->unsignedBigInteger('entity_id');
            $table->string('kind', 48);                           // id_front | agreement | signature_borrower | proof | …
            // NULLABLE, and it has to be. A field officer photographs a
            // national ID in a village with no signal: the attachment ROW is
            // real immediately, the bytes arrive whenever the phone next sees
            // a tower. Requiring a path at insert time made the row
            // un-pushable until the upload succeeded, which is precisely
            // backwards for an offline-first app — and it contradicted
            // `uploaded`, whose own default says the phone still holds the
            // only copy.
            $table->string('path', 500)->nullable();               // key in StoragePort; null until uploaded
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('checksum', 128)->nullable();          // so a re-upload is not a second file
            $table->string('uploaded_from_device', 64)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->boolean('uploaded')->default(false);          // false = the phone still holds the only copy

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_attach_rev');
            $table->index(['entity', 'entity_id'], 'idx_attach_entity');
            $table->index(['uploaded'], 'idx_attach_uploaded');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('attachments');
    }
};
