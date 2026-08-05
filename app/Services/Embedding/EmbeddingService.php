<?php

namespace App\Services\Embedding;

/**
 * Contract for services that turn text into dense float vectors.
 *
 * Implementations are expected to be pure and local when possible so callers can
 * degrade gracefully when the underlying engine (e.g. FFI or a local ONNX model)
 * is unavailable.
 */
interface EmbeddingService
{
    /**
     * Task prefix for text being embedded for storage, per the nomic-embed-text
     * model card. Stored documents are indexed as "search_document:" so queries
     * prefixed with "search_query:" retrieve them.
     */
    public const DOCUMENT_PREFIX = 'search_document: ';

    /**
     * Task prefix for text being embedded as a retrieval query.
     */
    public const QUERY_PREFIX = 'search_query: ';

    /**
     * Generate embeddings for a single text or a batch of texts.
     *
     * @param  string|list<string>  $texts
     * @return list<float>|list<list<float>> A single flat vector for a string input,
     *                                       or a list of flat vectors for an array input.
     *
     * @throws EmbeddingException When embeddings cannot be produced.
     */
    public function embed(string|array $texts): array;
}
