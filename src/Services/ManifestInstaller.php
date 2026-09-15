<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Services;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Filesystem\Filesystem;

class ManifestInstaller
{
    public const DEFAULT_STUB_EXTENSION = '.stub';

    public const DEFAULT_STUBS_DIR = 'stubs';

    public const RUNNER_FILE_PERMISSIONS = 0755;

    public const TYPE_MANIFEST = 'manifest';

    public const TYPE_RUNNER = 'runner';

    public const STATUS_CREATED = 'created';

    public const STATUS_SKIPPED = 'skipped';

    public const TOKEN_MANIFEST_PATH = '{{ manifestPath }}';

    public const TOKEN_RUNNER_NAME = '{{ runnerName }}';

    public function __construct(
        protected Filesystem $files = new Filesystem,
        protected StubEngine $stubEngine = new StubEngine,
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
                'type' => self::TYPE_MANIFEST,
                'status' => self::STATUS_SKIPPED,
                'message' => "Manifest [{$definition->filename}] already exists.",
            ];
        } else {
            $schema = $definition->resolveSchema();
            $manifest = Manifest::open($manifestPath, $schema, $this->files);
            $initialData = $schema->defaults();
            $manifest->save($initialData);

            $steps[] = [
                'type' => self::TYPE_MANIFEST,
                'status' => self::STATUS_CREATED,
                'message' => "Initialized manifest [{$definition->filename}] with schema defaults.",
            ];
        }

        // 2. Publish standalone runner if registered
        if ($definition->runnerPath !== null && $this->files->exists($definition->runnerPath)) {
            $runnerFilename = basename($definition->runnerPath);
            $runnerName = str_ends_with($runnerFilename, self::DEFAULT_STUB_EXTENSION)
                ? substr($runnerFilename, 0, -strlen(self::DEFAULT_STUB_EXTENSION))
                : $runnerFilename;

            $targetRunnerPath = $rootPath.DIRECTORY_SEPARATOR.$runnerName;
            $overrideFile = $rootPath.DIRECTORY_SEPARATOR.self::DEFAULT_STUBS_DIR.DIRECTORY_SEPARATOR.basename($definition->runnerPath);

            $created = $this->stubEngine->scaffoldFile(
                sourceFile: $definition->runnerPath,
                targetFile: $targetRunnerPath,
                tokens: [
                    self::TOKEN_MANIFEST_PATH => $definition->filename,
                    self::TOKEN_RUNNER_NAME => $runnerName,
                ],
                overrideFile: $overrideFile,
                force: $force,
            );

            if ($created) {
                @chmod($targetRunnerPath, self::RUNNER_FILE_PERMISSIONS);

                $steps[] = [
                    'type' => self::TYPE_RUNNER,
                    'status' => self::STATUS_CREATED,
                    'message' => "Published standalone executable runner to [./{$runnerName}].",
                ];
            } else {
                $steps[] = [
                    'type' => self::TYPE_RUNNER,
                    'status' => self::STATUS_SKIPPED,
                    'message' => "Standalone runner [./{$runnerName}] already exists.",
                ];
            }
        }

        return $steps;
    }
}
