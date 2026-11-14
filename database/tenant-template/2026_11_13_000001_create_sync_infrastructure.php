<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * The machinery every offline device depends on, before any business record exists.
 *
 * THE SYNC CONTRACT, which every synced table below repeats:
 *
 *   id          BIGINT, PRIMARY, **client-minted** — never auto-increment.
 *               A phone records work with no signal, prints a receipt with the
 *               id on it, and hands it to somebody. The id therefore has to be
 *               decided on the device and has to be unique across every device
 *               in the business. v1 mints `shard * 1_000_000 + counter`, where
 *               the shard is claimed from the server (see `id_shards`), and
 *               shard 0 is the legacy range every pre-sharding install holds.
 *   rev         BIGINT, server-assigned, strictly increasing per tenant. THE
 *               DELTA CURSOR. Not a timestamp: two devices can write in the
 *               same millisecond, phone clocks are wrong, and a pull that asks
 *               "everything after 12:04:03.233" either repeats rows or loses
 *               them. A counter has neither failure.
 *   updated_at  server clock, for humans and reports — never for the cursor.
 *   deleted_at  tombstone. A row that simply vanished would live forever on
 *               every phone that already had it.
 *   device_id   which handset last wrote it, for reconciliation and audit.
 */
/**
 * JSON-SHAPED COLUMNS ARE `longText`, NOT `json`, ON PURPOSE.
 *
 * The kernel supports MySQL, PostgreSQL, SQLite and SQL Server, and a `json`
 * column means a different thing on each: MySQL and PostgreSQL REFUSE a value
 * that does not parse, SQLite stores whatever it is handed, and SQL Server has
 * no JSON type at all (it compiles to NVARCHAR(MAX) and validates nothing). So
 * `json` would give this application three different behaviours depending on
 * where it was deployed.
 *
 * It is the permissive one that is correct here. These columns carry what v1
 * and the Firestore import hand over, and some of that does not parse — the
 * Customers suite pins the behaviour the app must have when it meets a blob it
 * cannot read: keep it, show what can be shown, and never silently discard it.
 * A column that rejects the row instead would fail the import, which is the
 * one outcome with no recovery.
 *
 * `longText` holds the same 4GB on MySQL that `json` did, and reads the same
 * on all four. Nothing queries these server-side; every one of them is decoded
 * in PHP.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        // The per-tenant revision counter. One row, bumped inside the same
        // transaction as the write it stamps, so `rev` is gapless and ordered.
        $schema->create('sync_revision', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();   // always 1
            $table->unsignedBigInteger('current_rev')->default(0);
            $table->dateTime('updated_at')->nullable();
        });

        // Id shards. A device claims one before it mints anything; the claim is
        // what turns "probably unique" into unique, which is the only acceptable
        // property for a number printed on a receipt.
        $schema->create('id_shards', function (Blueprint $table) {
            $table->unsignedInteger('shard')->primary();          // 1..999999; 0 = legacy
            $table->string('device_id', 64);
            // A central user id is a ULID — `Identity->userId` carries
            // users.user_id, a 26-character Crockford base32 string, not
            // users.id. Declared numeric, SQLite stored the ULID anyway and
            // every other driver the kernel supports either refuses the row
            // (MySQL strict, PostgreSQL) or coerces it to 0, which would
            // silently point every device and every staff row at one
            // non-existent account.
            $table->string('claimed_by_user', 40)->nullable();
            $table->dateTime('claimed_at');
            $table->dateTime('last_seen_at')->nullable();

            $table->unique(['device_id'], 'uq_id_shards_device');
        });

        // Every handset that has ever synced, and what it is allowed to do.
        $schema->create('devices', function (Blueprint $table) {
            $table->string('id', 64)->primary();                  // client-generated install id
            // A central user id is a ULID — `Identity->userId` carries
            // users.user_id, a 26-character Crockford base32 string, not
            // users.id. Declared numeric, SQLite stored the ULID anyway and
            // every other driver the kernel supports either refuses the row
            // (MySQL strict, PostgreSQL) or coerces it to 0, which would
            // silently point every device and every staff row at one
            // non-existent account.
            $table->string('user_id', 40)->nullable();            // who signed in on it
            $table->string('platform', 16)->nullable();           // android | ios
            $table->string('app_version', 32)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('push_token', 255)->nullable();
            $table->unsignedInteger('shard')->nullable();
            $table->unsignedBigInteger('last_pull_rev')->default(0);
            $table->dateTime('last_push_at')->nullable();
            $table->dateTime('last_pull_at')->nullable();
            $table->boolean('blocked')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['user_id'], 'idx_devices_user');
        });

        // The idempotency ledger. A phone retries a push it never got an answer
        // to — over a dropped connection that is the NORMAL case, not an edge
        // one — and a retried payment must not become two payments. The client
        // stamps each outbox entry with an op_id; this table remembers the
        // answer and replays it instead of applying the write twice.
        $schema->create('sync_operations', function (Blueprint $table) {
            $table->string('op_id', 64)->primary();               // client-minted, unique per op
            $table->string('device_id', 64);
            // A central user id is a ULID — `Identity->userId` carries
            // users.user_id, a 26-character Crockford base32 string, not
            // users.id. Declared numeric, SQLite stored the ULID anyway and
            // every other driver the kernel supports either refuses the row
            // (MySQL strict, PostgreSQL) or coerces it to 0, which would
            // silently point every device and every staff row at one
            // non-existent account.
            $table->string('user_id', 40)->nullable();
            $table->string('entity', 48);                         // 'loan', 'payment', …
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 16);                         // create | update | delete
            $table->string('status', 16);                         // applied | rejected | conflict
            $table->unsignedBigInteger('rev')->nullable();        // the rev it produced
            $table->text('error')->nullable();                    // why, when rejected
            $table->longText('request')->nullable();                  // what was sent, for disputes
            $table->dateTime('received_at');

            $table->index(['device_id', 'received_at'], 'idx_sync_ops_device');
            $table->index(['entity', 'entity_id'], 'idx_sync_ops_entity');
        });

        // Money that does not add up is a decision for a person, not a merge
        // rule. When the ledger (sum of payments) and the schedule disagree,
        // the loan is flagged and a row lands here for a human to settle.
        $schema->create('reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity', 48);
            $table->unsignedBigInteger('entity_id');
            $table->string('kind', 48);                           // ledger_mismatch | duplicate_receipt | …
            $table->longText('detail');                               // both figures, and where each came from
            $table->string('status', 16)->default('open');        // open | resolved | dismissed
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->dateTime('created_at');

            $table->index(['status', 'created_at'], 'idx_recon_status');
            $table->index(['entity', 'entity_id'], 'idx_recon_entity');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('reconciliations');
        $schema->dropIfExists('sync_operations');
        $schema->dropIfExists('devices');
        $schema->dropIfExists('id_shards');
        $schema->dropIfExists('sync_revision');
    }
};
