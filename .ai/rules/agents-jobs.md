---
paths:
  - 'app/Services/Embedding/**,app/Ai/Agents/BotAgent.php,app/Jobs/CaptureBotMemory.php'
---

# Agents Jobs

## Nomic task prefixes + canonical embedding model constant
Xenova/nomic-embed-text-v1 wants task prefixes: stored text is embedded as EmbeddingService::DOCUMENT_PREFIX ('search_document: ') and retrieval queries as EmbeddingService::QUERY_PREFIX ('search_query: '). Apply the prefixes at the call sites (CaptureBotMemory storage, BotAgent retrieval), never inside LocalEmbeddingService (keep embed() raw). The canonical model identifier is BotMemory::EMBEDDING_MODEL = 'Xenova/nomic-embed-text-v1' (EMBEDDING_DIM 768); LocalEmbeddingService, MemoryRepository filters and the factory all reference it — do not hardcode a different string.
