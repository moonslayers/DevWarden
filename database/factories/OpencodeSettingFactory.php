<?php

namespace Database\Factories;

use App\Models\OpencodeSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpencodeSetting>
 */
class OpencodeSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'root_projects_path' => '/home/junior/Projects',
            'mcp_command' => 'opencode-mcp',
            'data_db_path' => '/tmp/devwarden-test/opencode.db',
            'session_watch_since' => null,
        ];
    }
}
