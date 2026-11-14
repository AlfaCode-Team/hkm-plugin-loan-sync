# hkm-plugin-loan-sync

An [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) plugin providing
**`sync.offline`** — part of the **akasente** loan-management set.

Offline-first sync between the server (source of truth) and a handset (replica). Push applies a device outbox idempotently by op_id, one transaction per operation; pull returns a delta above a rev cursor and never advances that cursor past a row it did not hand over. Tables live in the project's tenant template, not here.

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-loan-sync.git
hkm plugins enable loan-sync
```

It installs as `plugins/LoanSync` with the namespace `Plugins\LoanSync\`, because
`hkm` takes the folder from `module.json`'s `name`, not from the repository.

## What it owns

Tables, shipped as `database/tenant-template/` migrations and published into a
project on enable:

- `sync_revision`
- `id_shards`
- `devices`
- `sync_operations`
- `reconciliations`
- `attachments`

Requires the domains: `database.management`

Publishes:

- `Plugins\LoanSync\API\Contracts\SyncServiceContract`
- `Plugins\LoanSync\API\Contracts\SyncRevisionContract`
- `Plugins\LoanSync\API\Contracts\AttachmentServiceContract`

## Routes

| Route | Name |
|---|---|
| `POST /api/sync/device` | `sync.device` |
| `POST /api/sync/shard` | `sync.shard` |
| `POST /api/sync/push` | `sync.push` |
| `POST /api/sync/attachment` | `sync.attachment.store` |
| `GET /api/sync/attachment/{id:num}` | `sync.attachment.show` |
| `GET /api/sync/pull` | `sync.pull` |

## Every write takes a sync revision

Each row carries `rev`, `device_id`, `created_at`, `updated_at` and
`deleted_at`. `rev` comes from `sync.offline`'s counter and is the cursor a
handset pulls against; a delete is a tombstone, never a `DELETE`. Ids are
client-minted from a claimed shard, so a phone with no signal can create a row
that will never collide with one the server made.

## Tests

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel phpunit
```

This plugin's suite reads the schema of every domain plugin in the set, because its catalogue is the allowlist for all of them. Those plugins are OPTIONAL: check out the ones you have beside this one and the catalogue test covers them; check out none and it skips, naming the tables it could not reach.

The suite migrates the real tenant template into a throwaway database and
drives the real adapter against it. `HKM_TEST_DB` picks which database that is:

```bash
phpunit                                                  # SQLite (default, no server)
HKM_TEST_DB=mysql  HKM_TEST_DB_USERNAME=root  phpunit
HKM_TEST_DB=pgsql  HKM_TEST_DB_PORT=5432      phpunit
HKM_TEST_DB=sqlsrv HKM_TEST_DB_USERNAME=sa    phpunit
```

The account needs permission to CREATE DATABASE; each run provisions
`hkm_test_<suite>_<random>` and drops it in teardown.

**Run at least one non-SQLite driver before believing a green suite.** SQLite
has no strict mode, no real column types, it tolerates a named placeholder used
twice, and it serialises writers for free — so it hides the whole class of
defect this code is most likely to have.

## Licence

MIT. See [LICENSE](LICENSE).
