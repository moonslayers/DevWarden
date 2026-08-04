<?php

namespace App\Models;

use App\Enums\AiProviderType;
use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property AiProviderType $provider
 * @property bool $is_enabled
 * @property string|null $api_key
 * @property string|null $base_url
 * @property string|null $model_text
 * @property int $failover_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'is_enabled', 'api_key', 'base_url', 'model_text', 'failover_order'])]
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    /**
     * Filter to providers that are enabled.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Order providers for the failover chain (lowest order first).
     */
    public function scopeByFailoverOrder(Builder $query): Builder
    {
        return $query->orderBy('failover_order');
    }

    /**
     * Get the enabled providers ordered for the failover chain.
     *
     * @return Collection<int, static>
     */
    public static function enabledOrdered(): Collection
    {
        return static::query()->enabled()->byFailoverOrder()->get();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProviderType::class,
            'is_enabled' => 'boolean',
            'api_key' => 'encrypted',
            'failover_order' => 'integer',
        ];
    }
}
