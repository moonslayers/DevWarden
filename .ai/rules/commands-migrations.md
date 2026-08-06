---
paths:
  - 'app/Services/Opencode/**,app/Console/Commands/**,database/migrations/**'
---

# Commands Migrations

## opencode_settings.data_db_path selects the opencode database path
OpencodeSessionStore resolves the opencode DB path in this order: constructor override > OpencodeSetting::singleton()->data_db_path > ~/.local/share/opencode/opencode.db (HOME fallback). Configured via opencode:settings --db-path= (validates the path is absolute and exists). Every store read is read-only (sqlite mode=ro + PRAGMA query_only) so opencode's own database can never be mutated.
