<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Services;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Manifest;
use Illuminate\Filesystem\Filesystem;

class ManifestInstaller
{
    public function __construct(
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * Install a registered manifest and its standalone runner.
     *
     * @return array<int, array{type: string, status: string, message: string}>
     */
    public function install(ManifestDefinition $definition, string $rootPath, bool $force = false): array
    {
        $steps = [];
        $manifestPath = $rootPath.DIRECTORY_SEPARATOR.$definition->filename;

        // 1. Install or update manifest JSON file
        if ($this->files->exists($manifestPath) && ! $force) {
            $steps[] = [
                'type' => 'manifest',
                'status' => 'skipped',
                'message' => "Manifest [{$definition->filename}] already exists.",
            ];
        } else {
            $schema = $definition->resolveSchema();
            $manifest = Manifest::open($manifestPath, $schema, $this->files);
            $initialData = $schema->defaults();
            $manifest->save($initialData);

            $steps[] = [
                'type' => 'manifest',
                'status' => 'created',
                'message' => "Initialized manifest [{$definition->filename}] with schema defaults.",
            ];
        }

        // 2. Publish standalone runner if registered
        if ($definition->runnerPath !== null && $this->files->exists($definition->runnerPath)) {
            $runnerFilename = basename($definition->runnerPath);
            $runnerName = str_ends_with($runnerFilename, '.stub')
                ? substr($runnerFilename, 0, -5)
                : $runnerFilename;

            $targetRunnerPath = $rootPath.DIRECTORY_SEPARATOR.$runnerName;

            if ($this->files->exists($targetRunnerPath) && ! $force) {
                $steps[] = [
                    'type' => 'runner',
                    'status' => 'skipped',
                    'message' => "Standalone runner [./{$runnerName}] already exists.",
                ];
            } else {
                $content = $this->files->get($definition->runnerPath);
                $compiled = str_replace(
                    ['{{ manifestPath }}', '{{ runnerName }}'],
                    [$definition->filename, $runnerName],
                    $content
                );

                $this->files->put($targetRunnerPath, $compiled);
                @chmod($targetRunnerPath, 0755);

                $steps[] = [
                    'type' => 'runner',
                    'status' => 'created',
                    'message' => "Published standalone executable runner to [./{$runnerName}].",
                ];
            }
        }

        return $steps;
    }
}
