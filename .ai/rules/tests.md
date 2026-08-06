---
paths:
  - 'tests/**'
---

# Tests

## RefreshDatabase is commented out in tests/Pest.php — add it per test file
tests/Pest.php imports but does NOT apply `->use(RefreshDatabase::class)`, so any test that touches the DB fails with "no such table" (pre-existing, affects DashboardTest too). Do not uncomment it globally without approval. New tests that hit the DB must declare `uses(RefreshDatabase::class);` in their own file.

## Global RefreshDatabase is now active in tests/Pest.php (supersedes older guidance)
The global `->use(RefreshDatabase::class)` in tests/Pest.php was previously commented out (starter-kit bug), causing "no such table: users" across pre-existing tests. It has been restored and the full suite passes. Any new Feature test that touches the DB is covered automatically — do NOT comment it out again. Per-file `uses(RefreshDatabase::class)` in older feature tests is redundant but harmless; new tests do not need it.

## Unit tests must declare uses(TestCase::class) to use facades/container
tests/Pest.php only binds Tests\TestCase (and RefreshDatabase) to in('Feature'). Tests under tests/Unit are plain PHPUnit by default, so app(), Http::fake() and other facades fail with "facade root has not been set". Add `use Tests\TestCase;` + `uses(TestCase::class);` at the top of each Unit test file that touches the container or facades. AI tool tests use Http::fake() + new Request([...]) and never hit real network (the Request class constructor takes the args array).

## Stub EmbeddingService in every test that runs BotAgent::respond() or the batch jobs
BotAgent::fake() does NOT protect embed(): respond() always calls EmbeddingService via buildPromptWithMemories(). Loading the real LocalEmbeddingService in tests loads the 138MB ONNX model (~280MB NATIVE memory per test that does not count against memory_limit 256M and accumulates across tests because the container is refreshed per test) → multi-GB RAM spikes that crash the desktop. Any test executing respond() directly (BotAgentTest, BotAgentMemoryTest) or via job handle() (ProcessTelegramPendingBatchTest, ProcessTelegramPendingBatchPlaceholderTest, CaptureBotMemoryTest) MUST bind a stub in the container: app()->instance(EmbeddingService::class, <anonymous class implementing EmbeddingService returning [[1.0,0,0,0]]>), in beforeEach when possible. BotAgentTest has a regression test asserting the container does not resolve LocalEmbeddingService. When verifying memory bugs, measure the FULL suite RAM (free -m/ps), not filtered files — grep for respond( in tests/ misses indirect calls via handle().

## Stub OpencodeSessionStore when asserting exact prompt text
BotAgent::respond() now injects <active_opencode_sessions> from the REAL opencode.db via app(OpencodeSessionStore::class). Any test asserting exact prompt equality (e.g. $prompt->prompt === 'Hello') MUST stub OpencodeSessionStore in the container with an anonymous subclass returning [] from activeSessions() — otherwise it depends on external state and fails. BotAgentMemoryTest and ProcessTelegramPendingBatchTest have the stub in beforeEach; keep it.
