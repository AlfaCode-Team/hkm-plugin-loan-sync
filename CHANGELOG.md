# Changelog

## 1.0.1 — 2026-11-14

- Remove `.git-msg`, a commit-message scratch file that was staged into the
  first commit and so shipped inside v1.0.0. No code change.

## 1.0.0 — 2026-11-14

First release. Extracted from the akasente project, where it was developed as a
local plugin, into a repository of its own so it can be versioned and installed
like any other HKM plugin.

- Solves `sync.offline`, owning 6 tables
  (`sync_revision`, `id_shards`, `devices`, `sync_operations`, `reconciliations`, `attachments`) and shipping their migrations.
- Portable across all four databases the kernel supports: MySQL, PostgreSQL,
  SQLite and SQL Server. Paging and row locking go through `SqlDialect` rather
  than being written once in whichever dialect the author had to hand; booleans
  are bound rather than written as integer literals; no statement repeats a
  named placeholder.
- Verified green on SQLite, MariaDB 13.0.2 and PostgreSQL 18.7. **SQL Server is
  written for but untested** — the development machine has no `pdo_sqlsrv` and
  no server to reach.
