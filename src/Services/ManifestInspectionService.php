<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Services;

use AlexKassel\ManifestEngine\DTOs\ManifestStatusReport;
use AlexKassel\ManifestEngine\DTOs\ManifestValidationReport;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Number;

class ManifestInspectionService
{
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        protected readonly ManifestManager $manager,
        protected readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * Inspect all registered manifests and report file presence, size, and timestamps.
     *
     * @return array<string, ManifestStatusReport>
     */
    public function getStatusReports(?string $basePath = null): array
    {
        $manifests = $this->manager->registry()->all();
        $rootPath = $basePath ?? (function_exists('base_path') ? base_path() : (string) (getcwd() ?: '.'));
        $reports = [];

        foreach ($manifests as $name => $def) {
            $manifestPath = $def->fullPath($rootPath);
            $hasManifest = $this->files->exists($manifestPath);

            $size = $hasManifest ? (int) $this->files->size($manifestPath) : null;
            $humanSize = $size !== null ? $this->formatBytes($size) : null;
            $lastModified = $hasManifest ? date(self::DATE_FORMAT, (int) $this->files->lastModified($manifestPath)) : null;

            $reports[$name] = new ManifestStatusReport(
                name: $name,
                filename: $def->filename,
                path: $manifestPath,
                exists: $hasManifest,
                sizeBytes: $size,
                humanSize: $humanSize,
                lastModified: $lastModified,
                description: $def->description,
            );
        }

        return $reports;
    }

    /**
     * Validate registered manifests against their schemas and return report objects.
     *
     * @return array<string, ManifestValidationReport>
     *
     * @throws ManifestException
     */
    public function validateAll(?string $targetName = null, ?string $basePath = null): array
    {
        $registry = $this->manager->registry();

        if (is_string($targetName) && trim($targetName) !== '') {
            $definition = $registry->get($targetName);
            if ($definition === null) {
                throw new ManifestException("No manifest registered with alias [{$targetName}].");
            }
            $manifests = [$targetName => $definition];
        } else {
            $manifests = $registry->all();
        }

        $reports = [];

        foreach ($manifests as $name => $def) {
            try {
                $manifest = $this->manager->get($name, $basePath);

                if (! $manifest->exists()) {
                    $reports[$name] = new ManifestValidationReport(
                        name: $name,
                        filename: $def->filename,
                        path: $manifest->path,
                        exists: false,
                        isValid: false,
                        errorMessage: 'File does not exist on disk.',
                    );

                    continue;
                }

                $manifest->load(forceFresh: true);

                $reports[$name] = new ManifestValidationReport(
                    name: $name,
                    filename: $def->filename,
                    path: $manifest->path,
                    exists: true,
                    isValid: true,
                );
            } catch (ManifestException $e) {
                $reports[$name] = new ManifestValidationReport(
                    name: $name,
                    filename: $def->filename,
                    path: $manifest->path,
                    exists: $manifest->exists(),
                    isValid: false,
                    errorMessage: $e->getMessage(),
                );
            }
        }

        return $reports;
    }

    /**
     * Format bytes into a human-readable string using Laravel Number helper.
     */
    public function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, precision: $bytes < 1024 ? 0 : 1);
    }
}
