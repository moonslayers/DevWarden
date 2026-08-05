<?php

namespace App\Services\Embedding;

use App\Models\BotMemory;
use Codewithkyrian\Transformers\Pipelines\FeatureExtractionPipeline;
use Codewithkyrian\Transformers\Pipelines\Pipeline;
use Throwable;

use function Codewithkyrian\Transformers\Pipelines\pipeline;

/**
 * Produces embeddings locally through the transformers-php ONNX runtime.
 *
 * The model is downloaded from Hugging Face on first use and cached inside the
 * project's storage directory. The loaded pipeline is kept in-process so repeated
 * calls reuse the same ONNX session instead of reloading the model.
 */
class LocalEmbeddingService implements EmbeddingService
{
    protected ?Pipeline $pipeline = null;

    public function __construct(
        protected string $modelName = BotMemory::EMBEDDING_MODEL,
        protected bool $quantized = true,
    ) {}

    /**
     * Generate embeddings, applying mean pooling over the token embeddings.
     *
     * @param  string|list<string>  $texts
     * @return list<float>|list<list<float>>
     *
     * @throws EmbeddingException When FFI is unavailable or the model cannot be loaded.
     */
    public function embed(string|array $texts): array
    {
        if (! extension_loaded('ffi')) {
            throw new EmbeddingException(
                'The FFI extension is required to run local embeddings. Enable it via "extension=ffi" in your php.ini.'
            );
        }

        $batch = is_array($texts) ? $texts : [$texts];

        if ($batch === []) {
            return [];
        }

        try {
            $result = $this->pipeline()->__invoke($batch, pooling: 'mean', normalize: true);
        } catch (Throwable $e) {
            throw new EmbeddingException(
                "Failed to generate embeddings with the local model '{$this->modelName}': {$e->getMessage()}",
                previous: $e,
            );
        }

        return is_array($texts) ? $result : $result[0];
    }

    /**
     * Get the feature-extraction pipeline, loading the model on first use.
     *
     * @throws EmbeddingException When the pipeline cannot be instantiated.
     */
    protected function pipeline(): FeatureExtractionPipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = pipeline(
                task: 'feature-extraction',
                modelName: $this->modelName,
                quantized: $this->quantized,
                cacheDir: $this->cacheDir(),
            );
        }

        if (! $this->pipeline instanceof FeatureExtractionPipeline) {
            throw new EmbeddingException(
                "Expected a feature-extraction pipeline for '{$this->modelName}', got ".get_class($this->pipeline).'.'
            );
        }

        return $this->pipeline;
    }

    /**
     * Directory where downloaded models are stored, inside the project storage.
     */
    protected function cacheDir(): string
    {
        return storage_path('app/embedding-models');
    }
}
