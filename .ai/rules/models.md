---
paths:
  - 'app/Services/Memory/**,app/Models/BotMemory.php'
---

# Models

## MemoryRepository::search has no excludeBeforeCreatedAt; scopeByCategory removed
MemoryRepository::search(int $chatId, array $queryEmbedding, int $topK=5, float $threshold=0.7) has no excludeBeforeCreatedAt param anymore (it was dead, timezone-sensitive, and would filter out the oldest — i.e. most useful — memories). BotMemory::scopeByCategory was also removed (the controller filters with ->where('category', $cat) directly). Do not reintroduce either.
