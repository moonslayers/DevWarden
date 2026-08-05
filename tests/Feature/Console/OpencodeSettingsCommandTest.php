<?php

use App\Models\OpencodeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('prints the current settings when run without options', function () {
    artisan('opencode:settings')
        ->expectsOutputToContain('Root projects path')
        ->expectsOutputToContain('opencode-mcp')
        ->assertSuccessful();

    $settings = OpencodeSetting::singleton();

    expect($settings->root_projects_path)->toBe('/home/junior/Projects');
    expect($settings->mcp_command)->toBe('opencode-mcp');
});

test('updates the root projects path when it is absolute and exists', function () {
    $path = sys_get_temp_dir().'/devwarden-opencode-'.uniqid();
    mkdir($path);

    try {
        artisan('opencode:settings', ['--root' => $path])
            ->expectsOutputToContain('Opencode settings updated.')
            ->expectsOutputToContain($path)
            ->assertSuccessful();

        expect(OpencodeSetting::singleton()->root_projects_path)->toBe($path);
    } finally {
        rmdir($path);
    }
});

test('fails when the root projects path is not absolute', function () {
    artisan('opencode:settings', ['--root' => 'relative/projects'])
        ->expectsOutputToContain('is not absolute')
        ->assertFailed();

    expect(OpencodeSetting::singleton()->root_projects_path)->toBe('/home/junior/Projects');
});

test('fails when the root projects path does not exist on disk', function () {
    artisan('opencode:settings', ['--root' => '/nonexistent/devwarden-opencode'])
        ->expectsOutputToContain('does not exist on disk')
        ->assertFailed();

    expect(OpencodeSetting::singleton()->root_projects_path)->toBe('/home/junior/Projects');
});

test('fails when the root projects path is the filesystem root', function () {
    artisan('opencode:settings', ['--root' => '/'])
        ->expectsOutputToContain('filesystem root')
        ->assertFailed();

    expect(OpencodeSetting::singleton()->root_projects_path)->toBe('/home/junior/Projects');
});

test('updates the mcp command', function () {
    artisan('opencode:settings', ['--mcp-command' => 'opencode-mcp --debug'])
        ->expectsOutputToContain('Opencode settings updated.')
        ->expectsOutputToContain('opencode-mcp --debug')
        ->assertSuccessful();

    expect(OpencodeSetting::singleton()->mcp_command)->toBe('opencode-mcp --debug');
});

test('fails when the mcp command is empty', function () {
    artisan('opencode:settings', ['--mcp-command' => '   '])
        ->expectsOutputToContain('must not be empty')
        ->assertFailed();
});
