<?php

namespace App\Console\Commands;

use App\Models\OpencodeSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('opencode:settings {--show : Display the current opencode settings} {--root= : Set the absolute root projects path} {--mcp-command= : Set the opencode MCP command} {--db-path= : Set the absolute path to the opencode SQLite database file}')]
#[Description('Show or update the opencode settings (root projects path, MCP command and database path)')]
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
        $dbPath = $this->option('db-path');

        if ($root !== null && ! $this->validateRootPath($root)) {
            return self::FAILURE;
        }

        if ($dbPath !== null && ! $this->validateDbPath($dbPath)) {
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

        if ($dbPath !== null) {
            $settings->data_db_path = $dbPath;
        }

        $changed = $settings->isDirty();

        $settings->save();

        if ($changed) {
            $this->components->info('Opencode settings updated.');
        }

        $this->components->twoColumnDetail('Root projects path', $settings->root_projects_path);
        $this->components->twoColumnDetail('MCP command', $settings->mcp_command);
        $this->components->twoColumnDetail('Data DB path', $settings->data_db_path ?? 'Not set');
        $this->components->twoColumnDetail('Session watch since', $settings->session_watch_since?->toDateTimeString() ?? 'Not set');

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

    /**
     * Validate that the database path is absolute and points to an existing file.
     */
    private function validateDbPath(string $path): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->components->error(sprintf('The database path "%s" is not absolute.', $path));

            return false;
        }

        if (! is_file($path)) {
            $this->components->error(sprintf('The database path "%s" does not point to an existing file on disk.', $path));

            return false;
        }

        return true;
    }
}
