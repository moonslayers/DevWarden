<?php

namespace Database\Seeders;

use App\Enums\BotSubAgentType;
use App\Models\BotSubAgent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BotSubAgentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the immutable system vision sub-agent default.
     *
     * Idempotent: only the system identity fields (name, type, is_system,
     * sort_order) are (re)asserted. User-set values on an existing row
     * (is_active, ai_provider_id, model, system_prompt) are never reset.
     */
    public function run(): void
    {
        BotSubAgent::query()->updateOrCreate(
            ['slug' => 'vision'],
            [
                'name' => 'Vision',
                'type' => BotSubAgentType::Vision,
                'is_system' => true,
                'sort_order' => 0,
            ]
        );
    }
}
