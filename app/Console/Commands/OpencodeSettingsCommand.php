<?php

namespace App\Console\Commands;

use App\Models\OpencodeSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('opencode:settings {--show : Display the current opencode settings} {--root= : Set the absolute root projects path} {--mcp-command= : Set the opencode MCP command}')]
#[Description('Show or update the opencode settings (root projects path and MCP command)')]
class OpencodeSettingsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $settings = OpencodeSetting::singleton();

        $root = $this->option('root');
        $mcpCommand = $this->option('mcp-command');

        if ($root !== null && ! $this->validateRootPath($root)) {
            return self::FAILURE;
        }

        if ($mcpCommand !== null) {
            $mcpCommand = trim($mcpCommand);

            if ($mcpCommand === '') {
                $this->components->error('The MCP command must not be empty.');

                return self::FAILURE;
            }

            $settings->mcp_command = $mcpCommand;
        }

        if ($root !== null) {
            $settings->root_projects_path = $root;
        }

        $changed = $settings->isDirty();

        $settings->save();

        if ($changed) {
            $this->components->info('Opencode settings updated.');
        }

        $this->components->twoColumnDetail('Root projects path', $settings->root_projects_path);
        $this->components->twoColumnDetail('MCP command', $settings->mcp_command);

        return self::SUCCESS;
    }

    /**
     * Validate that the root projects path is absolute and exists on disk.
     */
    private function validateRootPath(string $path): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->components->error(sprintf('The root projects path "%s" is not absolute.', $path));

            return false;
        }

        if (rtrim($path, DIRECTORY_SEPARATOR) === '') {
            $this->components->error('The root projects path cannot be the filesystem root ("/").');

            return false;
        }

        if (! is_dir($path)) {
            $this->components->error(sprintf('The root projects path "%s" does not exist on disk.', $path));

            return false;
        }

        return true;
    }
}
