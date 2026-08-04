---
paths:
  - 'tests/**'
---

# Tests

## RefreshDatabase is commented out in tests/Pest.php — add it per test file
tests/Pest.php imports but does NOT apply `->use(RefreshDatabase::class)`, so any test that touches the DB fails with "no such table" (pre-existing, affects DashboardTest too). Do not uncomment it globally without approval. New tests that hit the DB must declare `uses(RefreshDatabase::class);` in their own file.

## Global RefreshDatabase is now active in tests/Pest.php (supersedes older guidance)
The global `->use(RefreshDatabase::class)` in tests/Pest.php was previously commented out (starter-kit bug), causing "no such table: users" across pre-existing tests. It has been restored and the full suite passes. Any new Feature test that touches the DB is covered automatically — do NOT comment it out again. Per-file `uses(RefreshDatabase::class)` in older feature tests is redundant but harmless; new tests do not need it.
