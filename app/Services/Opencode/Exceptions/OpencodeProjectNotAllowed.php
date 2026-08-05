<?php

namespace App\Services\Opencode\Exceptions;

/**
 * Thrown when a target directory falls outside the configured root projects path.
 */
class OpencodeProjectNotAllowed extends OpencodeException
{
    public function __construct(string $directory, string $rootProjectsPath)
    {
        parent::__construct(
            "Directory [{$directory}] is outside the allowed root projects path [{$rootProjectsPath}].",
        );
    }
}
