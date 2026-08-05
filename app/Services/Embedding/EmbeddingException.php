<?php

namespace App\Services\Embedding;

use RuntimeException;

/**
 * Raised when embeddings cannot be produced, so callers can degrade gracefully.
 *
 * Examples: the FFI extension is unavailable, the local model cannot be
 * downloaded, or the ONNX runtime fails to run.
 */
class EmbeddingException extends RuntimeException {}
