<?php

namespace App\Models;

use Database\Factories\BotSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $content
 * @property array<int, string>|null $trigger_keywords
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'description', 'content', 'trigger_keywords',
    'active', 'sort_order',
])]
class BotSkill extends Model
{
    /** @use HasFactory<BotSkillFactory> */
    use HasFactory;

    /**
     * Filter to skills that are enabled.
     *
     * @param  Builder<BotSkill>  $query
     * @return Builder<BotSkill>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Order skills by their injection order.
     *
     * @param  Builder<BotSkill>  $query
     * @return Builder<BotSkill>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Whether a piece of user text should trigger this skill.
     *
     * Matches when the skill is active and any non-empty trigger keyword
     * appears in the text as a case-insensitive substring. Skills without
     * trigger keywords never match on text alone.
     */
    public function matches(string $text): bool
    {
        if (! $this->active || empty($this->trigger_keywords)) {
            return false;
        }

        foreach ($this->trigger_keywords as $keyword) {
            $keyword = trim((string) $keyword);

            if ($keyword !== '' && Str::contains($text, $keyword, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_keywords' => 'array',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
