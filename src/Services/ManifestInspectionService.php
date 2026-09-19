<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Services;

use AlexKassel\ManifestEngine\DTOs\ManifestStatusReport;
use AlexKassel\ManifestEngine\DTOs\ManifestValidationReport;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

final readonly class ManifestInspectionService
{
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        public ManifestManager $manager,
    ) {}

    /**
     * Inspect all registered manifests and report file presence, size, and timestamps.
     *
     * @return array<string, ManifestStatusReport>
     */
    public function getStatusReports(?string $basePath = null): array
    {
        $manifests = $this->manager->registry->all();
        $reports = [];

        foreach ($manifests as $name => $def) {
            $manifestPath = $this->resolvePath($def->path, $basePath);
            $hasManifest = File::exists($manifestPath);

            $size = $hasManifest ? (int) File::size($manifestPath) : null;
            $humanSize = $size !== null ? $this->formatBytes($size) : null;
            $lastModified = $hasManifest ? date(self::DATE_FORMAT, (int) File::lastModified($manifestPath)) : null;

            $reports[$name] = new ManifestStatusReport(
                name: $name,
                filename: basename($def->path),
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
     * Validate one or all registered manifests for existence and valid JSON syntax.
     *
     * @return array<string, ManifestValidationReport>
     *
     * @throws ManifestException
     */
    public function validateAll(?string $targetName = null, ?string $basePath = null): array
    {
        $registry = $this->manager->registry;

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
            $targetPath = $this->resolvePath($def->path, $basePath);
            $manifest = new Manifest($targetPath, $def->schema);

            try {
                if (! $manifest->exists()) {
                    $reports[$name] = new ManifestValidationReport(
                        name: $name,
                        filename: basename($def->path),
                        path: $manifest->path,
                        exists: false,
                        isValid: false,
                        errorMessage: 'File does not exist on disk.',
                    );

                    continue;
                }

                $manifest->fresh();

                $reports[$name] = new ManifestValidationReport(
                    name: $name,
                    filename: basename($def->path),
                    path: $manifest->path,
                    exists: true,
                    isValid: true,
                );
            } catch (ManifestException $e) {
                $reports[$name] = new ManifestValidationReport(
                    name: $name,
                    filename: basename($def->path),
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

    /**
     * Resolve target manifest path with optional custom base path override.
     */
    protected function resolvePath(string $definitionPath, ?string $basePath = null): string
    {
        return $basePath !== null
            ? rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.basename($definitionPath)
            : $definitionPath;
    }
}
