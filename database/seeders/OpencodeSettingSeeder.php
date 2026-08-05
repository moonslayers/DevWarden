<?php

namespace Database\Seeders;

use App\Models\OpencodeSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OpencodeSettingSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the singleton opencode settings row with defaults.
     */
    public function run(): void
    {
        OpencodeSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'root_projects_path' => '/home/junior/Projects',
                'mcp_command' => 'opencode-mcp',
            ]
        );
    }
}
