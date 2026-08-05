---
paths:
  - 'database/migrations/**,app/Services/Memory/**'
---

# Memory

## SQLite: binary() for blobs; no bare OFFSET
Blueprint has no `blob()` method — use `$table->binary()` for BLOB columns in SQLite. SQLite rejects `OFFSET n` without a LIMIT, so avoid `->skip()`/`->offset()` on delete-prune queries; instead compute the excess count and `->limit($excess)` on the oldest rows.
