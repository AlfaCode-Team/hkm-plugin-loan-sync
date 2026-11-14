<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanSync\Application\Services\SyncService;
use Plugins\LoanSync\Domain\EntityCatalogue;
use Plugins\LoanSync\Domain\ShardClaim;
use Tests\Unit\Plugins\LoanSync\Support\SyncHarness;

#[CoversClass(SyncService::class)]
final class SyncServiceTest extends TestCase
{
    private ?SyncHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function harness(): SyncHarness
    {
        return $this->harness ??= new SyncHarness();
    }

    public function test_a_created_row_is_applied_and_takes_the_first_rev(): void
    {
        $h = $this->harness();

        $out = $h->sync->push('device-a', [
            SyncHarness::op('op-1', 'customers', 'create', 1_000_001, SyncHarness::customer()),
        ]);

        self::assertSame('applied', $out['results'][0]['status']);
        self::assertSame(1, $out['results'][0]['rev']);

        $row = $h->db->queryOne('SELECT * FROM customers WHERE id = 1000001');

        self::assertNotNull($row);
        self::assertSame('Aminah Nakato', $row['full_name']);
        self::assertSame('device-a', $row['device_id'], 'the server stamps the device, not the client');
    }

    public function test_a_retried_op_id_is_replayed_and_writes_nothing(): void
    {
        $h  = $this->harness();
        $op = SyncHarness::op('op-dup', 'customers', 'create', 1_000_002, SyncHarness::customer('Juma Okello'));

        $first  = $h->sync->push('device-a', [$op]);
        $second = $h->sync->push('device-a', [$op]);

        self::assertSame('applied', $first['results'][0]['status']);
        self::assertSame('replayed', $second['results'][0]['status']);
        self::assertSame($first['results'][0]['rev'], $second['results'][0]['rev']);

        $count = $h->db->queryOne('SELECT COUNT(*) AS n FROM customers');

        self::assertSame(1, (int) $count['n'], 'a retry must not create a second row');
    }

    public function test_a_stale_base_rev_is_a_conflict_and_is_not_recorded(): void
    {
        $h = $this->harness();

        $h->sync->push('device-a', [
            SyncHarness::op('op-c', 'customers', 'create', 1_000_003, SyncHarness::customer()),
        ]);

        // Someone else moves the row on.
        $h->sync->push('device-b', [
            SyncHarness::op('op-u1', 'customers', 'update', 1_000_003, ['phone' => '0700111222'], baseRev: 1),
        ]);

        $stale = $h->sync->push('device-a', [
            SyncHarness::op('op-u2', 'customers', 'update', 1_000_003, ['phone' => '0999999999'], baseRev: 1),
        ]);

        self::assertSame('conflict', $stale['results'][0]['status']);
        self::assertSame(2, $stale['results'][0]['server_rev']);

        $row = $h->db->queryOne('SELECT phone FROM customers WHERE id = 1000003');
        self::assertSame('0700111222', $row['phone'], 'the conflicting write must not land');

        // Not recorded: the device pulls, re-decides and sends the same op_id
        // again. If the conflict had been stored it would replay forever.
        $retry = $h->sync->push('device-a', [
            SyncHarness::op('op-u2', 'customers', 'update', 1_000_003, ['phone' => '0888888888'], baseRev: 2),
        ]);

        self::assertSame('applied', $retry['results'][0]['status']);
    }

    public function test_a_delete_is_a_tombstone_that_keeps_the_row(): void
    {
        $h = $this->harness();

        $h->sync->push('device-a', [
            SyncHarness::op('op-c', 'customers', 'create', 1_000_004, SyncHarness::customer()),
            SyncHarness::op('op-d', 'customers', 'delete', 1_000_004),
        ]);

        $row = $h->db->queryOne('SELECT * FROM customers WHERE id = 1000004');

        self::assertNotNull($row, 'the row stays: the tombstone has to travel, and it is evidence');
        self::assertNotNull($row['deleted_at']);
        self::assertSame(2, (int) $row['rev']);
    }

    public function test_a_client_cannot_write_a_server_owned_column(): void
    {
        $h = $this->harness();

        $out = $h->sync->push('device-a', [
            SyncHarness::op('op-x', 'customers', 'create', 1_000_005, SyncHarness::customer() + [
                'rev'        => 9999,
                'device_id'  => 'somebody-elses-phone',
                'deleted_at' => '2020-01-01 00:00:00',
                'not_a_col'  => 'x',
            ]),
        ]);

        self::assertSame('applied', $out['results'][0]['status']);
        self::assertContains('rev', $out['results'][0]['ignored_fields']);
        self::assertContains('device_id', $out['results'][0]['ignored_fields']);
        self::assertContains('deleted_at', $out['results'][0]['ignored_fields']);

        $row = $h->db->queryOne('SELECT * FROM customers WHERE id = 1000005');

        self::assertSame(1, (int) $row['rev']);
        self::assertSame('device-a', $row['device_id']);
        self::assertNull($row['deleted_at']);
    }

    public function test_an_unknown_entity_is_rejected_without_touching_the_rest(): void
    {
        $h = $this->harness();

        $out = $h->sync->push('device-a', [
            SyncHarness::op('op-bad', 'secret_table', 'create', 1, ['x' => 1]),
            SyncHarness::op('op-ok', 'customers', 'create', 1_000_006, SyncHarness::customer()),
        ]);

        self::assertSame('rejected', $out['results'][0]['status']);
        self::assertSame('applied', $out['results'][1]['status'], 'one bad op must not sink the batch');
    }

    public function test_a_pull_returns_changes_and_a_cursor_at_the_highest_rev_delivered(): void
    {
        $h = $this->harness();

        $h->sync->push('device-a', [
            SyncHarness::op('o1', 'customers', 'create', 1_000_010, SyncHarness::customer('A A')),
            SyncHarness::op('o2', 'customers', 'create', 1_000_011, SyncHarness::customer('B B')),
        ]);

        $page = $h->sync->pull('device-b', 0);

        self::assertFalse($page->hasMore);
        self::assertSame(2, $page->count);
        self::assertSame(2, $page->cursor);
        self::assertCount(2, $page->changes['customers']);

        $next = $h->sync->pull('device-b', $page->cursor);

        self::assertSame(0, $next->count, 'nothing new above the cursor');
        self::assertSame(2, $next->cursor, 'an empty page leaves the cursor where it was');
    }

    /**
     * The one that matters: paging must never skip a row.
     *
     * Rows are created across two entities so their revs interleave, then the
     * whole set is pulled one small page at a time. Every row must arrive
     * exactly once. A cursor that advanced past an undelivered rev would show
     * up here as a missing id, which is precisely the bug that loses a day of
     * a field officer's work with no error anywhere.
     */
    public function test_paging_never_skips_a_row_when_entities_interleave(): void
    {
        $h = $this->harness();

        $ops      = [];
        $expected = ['customers' => [], 'expenses' => []];

        for ($i = 0; $i < 12; $i++) {
            $cid = 1_000_100 + $i;
            $eid = 2_000_100 + $i;

            $ops[] = SyncHarness::op("c{$i}", 'customers', 'create', $cid, SyncHarness::customer("Cust {$i}"));
            $ops[] = SyncHarness::op("e{$i}", 'expenses', 'create', $eid, [
                'branch_id'   => 1,
                'category'    => 'transport',
                'amount'      => 5000 + $i,
                'description' => "boda {$i}",
                'spent_on'    => '2026-11-13',
                'recorded_by' => 1,
            ]);

            $expected['customers'][] = $cid;
            $expected['expenses'][]  = $eid;
        }

        $pushed = $h->sync->push('device-a', $ops);

        foreach ($pushed['results'] as $r) {
            self::assertSame('applied', $r['status'], $r['error'] ?? '');
        }

        $seen   = ['customers' => [], 'expenses' => []];
        $cursor = 0;
        $rounds = 0;

        do {
            $page = $h->sync->pull('device-b', $cursor, null, limit: 5);

            foreach ($page->changes as $entity => $rows) {
                foreach ($rows as $row) {
                    $seen[$entity][] = (int) $row['id'];
                }
            }

            self::assertGreaterThanOrEqual($cursor, $page->cursor, 'a cursor must never go backwards');

            if ($page->count === 0 && $page->hasMore) {
                self::fail('a page delivered nothing yet claimed more — the device would spin forever');
            }

            $cursor = $page->cursor;
            $rounds++;

            self::assertLessThan(60, $rounds, 'paging failed to terminate');
        } while ($page->hasMore);

        sort($seen['customers']);
        sort($seen['expenses']);

        self::assertSame($expected['customers'], $seen['customers'], 'a customer was skipped by the cursor');
        self::assertSame($expected['expenses'], $seen['expenses'], 'an expense was skipped by the cursor');

        self::assertSame(
            array_values(array_unique($seen['customers'])),
            $seen['customers'],
            'a row was delivered twice',
        );
    }

    public function test_a_shard_is_claimed_once_and_returned_unchanged_after(): void
    {
        $h = $this->harness();

        $first  = $h->sync->claimShard('device-a');
        $second = $h->sync->claimShard('device-a');
        $other  = $h->sync->claimShard('device-b');

        self::assertTrue($first->freshlyClaimed);
        self::assertFalse($second->freshlyClaimed);
        self::assertSame($first->shard, $second->shard, 'a device must keep its shard across restarts');
        self::assertNotSame($first->shard, $other->shard, 'two devices must never share a range');

        self::assertSame(ShardClaim::FIRST, $first->shard, 'shard 0 is the legacy range and is never handed out');
        self::assertSame($first->shard * ShardClaim::SPAN, $first->floor());
    }

    public function test_a_blocked_device_is_refused(): void
    {
        $h = $this->harness();

        $h->sync->registerDevice('device-a', ['platform' => 'android']);
        $h->db->execute(
            'UPDATE devices SET blocked = :blocked WHERE id = :id',
            ['blocked' => true, 'id' => 'device-a'],
        );

        $this->expectExceptionMessage('This device has been blocked.');

        $h->sync->push('device-a', [
            SyncHarness::op('op-1', 'customers', 'create', 1_000_200, SyncHarness::customer()),
        ]);
    }

    public function test_registering_a_device_does_not_clear_its_block(): void
    {
        $h = $this->harness();

        $h->sync->registerDevice('device-a', ['platform' => 'android', 'app_version' => '1.0.0']);
        $h->db->execute(
            'UPDATE devices SET blocked = :blocked WHERE id = :id',
            ['blocked' => true, 'id' => 'device-a'],
        );

        try {
            $h->sync->registerDevice('device-a', ['platform' => 'android', 'app_version' => '1.0.1']);
            self::fail('a blocked handset must not re-register itself back into service');
        } catch (\Throwable $e) {
            self::assertStringContainsString('blocked', $e->getMessage());
        }

        $row = $h->db->queryOne('SELECT blocked FROM devices WHERE id = :id', ['id' => 'device-a']);

        self::assertSame(1, (int) $row['blocked']);
    }

    public function test_a_handset_cannot_rewrite_a_settled_payment(): void
    {
        $h = $this->harness();

        $h->sync->push('device-a', [
            SyncHarness::op('p1', 'loan_payments', 'create', 1_000_300, [
                'receipt_number' => 'R-0001',
                'loan_id'        => 1,
                'customer_id'    => 2,
                'customer_name'  => 'Aminah Nakato',
                'branch_id'      => 1,
                'amount'         => 50_000,
                'principal_part' => 40_000,
                'interest_part'  => 10_000,
                'penalty_part'   => 0,
                'payment_method' => 'cash',
                'payment_date'   => '2026-11-13',
                'recorded_at'    => '2026-11-13 10:00:00',
                'recorded_by'    => 7,
            ]),
        ]);

        $out = $h->sync->push('device-a', [
            SyncHarness::op('p2', 'loan_payments', 'update', 1_000_300, [
                'amount'         => 5_000,
                'principal_part' => 5_000,
                'interest_part'  => 0,
                'voided'         => 1,
                'void_reason'    => 'entered twice',
            ]),
        ]);

        // The void is allowed; the amounts are not.
        self::assertSame('applied', $out['results'][0]['status']);
        self::assertContains('amount', $out['results'][0]['ignored_fields']);
        self::assertContains('principal_part', $out['results'][0]['ignored_fields']);

        $row = $h->db->queryOne('SELECT * FROM loan_payments WHERE id = 1000300');

        self::assertSame(
            50000,
            (int) $row['amount'],
            'the server must still agree with the receipt in the customer\'s pocket',
        );
        self::assertSame(1, (int) $row['voided']);
        self::assertSame('entered twice', $row['void_reason']);
    }

    public function test_a_payment_can_never_be_tombstoned(): void
    {
        $h = $this->harness();

        $h->sync->push('device-a', [
            SyncHarness::op('p1', 'loan_payments', 'create', 1_000_301, [
                'receipt_number' => 'R-0002',
                'loan_id'        => 1,
                'customer_id'    => 2,
                'customer_name'  => 'Juma Okello',
                'branch_id'      => 1,
                'amount'         => 25_000,
                'principal_part' => 25_000,
                'interest_part'  => 0,
                'penalty_part'   => 0,
                'payment_method' => 'cash',
                'payment_date'   => '2026-11-13',
                'recorded_at'    => '2026-11-13 11:00:00',
                'recorded_by'    => 7,
            ]),
        ]);

        $out = $h->sync->push('device-a', [
            SyncHarness::op('p-del', 'loan_payments', 'delete', 1_000_301),
        ]);

        self::assertSame('rejected', $out['results'][0]['status']);

        $row = $h->db->queryOne('SELECT deleted_at FROM loan_payments WHERE id = 1000301');

        self::assertNull($row['deleted_at'], 'a payment that existed can never be forgotten');
    }

    /**
     * The adapter wraps PDO failures in its OWN exception type, so a
     * `catch (\PDOException)` in a repository never fires. That was true of 39
     * catch blocks across this project and every one of them was dead code:
     * failures escaped untranslated, and the retry paths that depended on
     * catching a constraint violation never ran.
     */
    public function test_a_constraint_violation_is_translated_not_leaked(): void
    {
        $h = $this->harness();

        $h->db->execute(
            "INSERT INTO devices (id, created_at, updated_at) VALUES ('dup','2026-01-01','2026-01-01')",
        );

        try {
            $h->db->execute(
                "INSERT INTO devices (id, created_at, updated_at) VALUES ('dup','2026-01-01','2026-01-01')",
            );
            self::fail('the second insert should have violated the primary key');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(
                \PDOException::class,
                $e,
                'if this ever becomes a PDOException again, revisit every catch in this project',
            );
        }

        // And the repository layer turns it into the kernel's own type.
        $shards = new \Plugins\LoanSync\Infrastructure\Persistence\ShardRepository($h->db);
        $first  = $shards->claim('device-one', null);
        $second = $shards->claim('device-one', null);

        self::assertSame($first->shard, $second->shard, 'the retry path must survive a collision');
    }

    /**
     * The offline case this whole system exists for.
     *
     * A field officer photographs a national ID in a village with no signal.
     * The attachment ROW is a real fact immediately; the bytes travel whenever
     * the phone next finds a tower. `attachments.path` was NOT NULL, so the
     * push was refused by the database and the row could not exist until the
     * upload had already succeeded — exactly backwards for an offline-first
     * app, and a contradiction of `uploaded`, whose default says the phone
     * still holds the only copy.
     */
    public function test_a_handset_can_record_an_attachment_before_the_bytes_arrive(): void
    {
        $h = $this->harness();

        $out = $h->sync->push('device-a', [
            SyncHarness::op('op-att', 'attachments', 'create', 1_000_400, [
                'entity'      => 'customer',
                'entity_id'   => 1_000_001,
                'kind'        => 'id_front',
                'captured_at' => '2026-11-13 09:30:00',
            ]),
        ]);

        self::assertSame('applied', $out['results'][0]['status'], $out['results'][0]['error'] ?? '');

        $row = $h->db->queryOne('SELECT * FROM attachments WHERE id = 1000400');

        self::assertNotNull($row, 'the record of the photograph exists before the photograph does');
        self::assertNull($row['path'], 'no server path yet — the phone still holds the only copy');
        self::assertSame(0, (int) $row['uploaded']);
        self::assertSame('id_front', $row['kind']);

        // And it reaches other devices, so a supervisor can see a document was
        // captured even while the bytes are still on the officer's phone.
        $page = $h->sync->pull('device-b', 0, ['attachments']);

        self::assertSame(1, $page->count);
    }

    public function test_the_catalogue_matches_the_migrated_schema(): void
    {
        $h = $this->harness();

        // The catalogue spans tables owned by the seven domain plugins, which
        // need not be checked out beside this one. Where they are not, say so
        // and stop — a partial pass reported as a pass is worse than a skip.
        $absent = [];

        foreach (EntityCatalogue::all() as $entity) {
            if ($h->database->columns($entity->table) === []) {
                $absent[] = $entity->table;
            }
        }

        if ($absent !== []) {
            self::markTestSkipped(sprintf(
                'These tables belong to plugins not installed beside this one, so the '
                . 'catalogue cannot be checked against the schema: %s',
                implode(', ', $absent),
            ));
        }

        foreach (EntityCatalogue::all() as $entity) {
            // Asked through TestDatabase, because `PRAGMA table_info` is
            // SQLite's alone: run anywhere else, this test used to fail with a
            // syntax error rather than check anything.
            $columns = $h->database->columns($entity->table);

            self::assertNotEmpty($columns, sprintf('table %s does not exist', $entity->table));

            foreach ($entity->columns as $column) {
                self::assertContains(
                    $column,
                    $columns,
                    sprintf('%s.%s is in the catalogue but not in the schema', $entity->table, $column),
                );
            }

            foreach (['id', 'rev', 'device_id', 'created_at', 'updated_at', 'deleted_at'] as $required) {
                self::assertContains($required, $columns, sprintf('%s lacks %s', $entity->table, $required));
            }

            // The catalogue's boolean list is what normalises a pull payload,
            // so a column that becomes boolean without being named here would
            // start travelling as `true` from PostgreSQL and `1` from MySQL.
            $declared = $entity->booleans;
            sort($declared);

            $actual = $h->database->booleanColumns($entity->table);

            if ($actual === null) {
                // SQLite cannot name its boolean columns — see
                // TestDatabase::booleanColumns(). All it can prove is that the
                // columns the catalogue declares are real ones; run the suite
                // with AKASENTE_TEST_DB=mysql or pgsql for the strict check.
                foreach ($declared as $column) {
                    self::assertContains($column, $columns, sprintf(
                        '%s.%s is declared boolean but is not in the schema',
                        $entity->table,
                        $column,
                    ));
                }

                continue;
            }

            self::assertSame($declared, $actual, sprintf(
                '%s: the catalogue\'s boolean columns do not match the schema',
                $entity->table,
            ));
        }
    }
}
