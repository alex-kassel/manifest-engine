<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Services;

use AlexKassel\ManifestEngine\DTOs\ManifestStatusReport;
use AlexKassel\ManifestEngine\DTOs\ManifestValidationReport;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

#[Singleton]
final readonly class ManifestInspectionService
{
    public function __construct(
        public ManifestRegistry $registry,
    ) {}

    /**
     * Inspect all registered manifests and report file presence, size, and timestamps.
     *
     * @return array<string, ManifestStatusReport>
     */
    public function getStatusReports(): array
    {
        $reports = [];

        foreach ($this->registry->all() as $name => $definition) {
            $hasManifest = File::exists($definition->path);

            $size = $hasManifest ? (int) File::size($definition->path) : null;
            $humanSize = $size !== null ? $this->formatBytes($size) : null;
            $lastModified = $hasManifest
                ? Carbon::createFromTimestamp((int) File::lastModified($definition->path))->toDateTimeString()
                : null;

            $reports[$name] = new ManifestStatusReport(
                name: $name,
                filename: basename($definition->path),
                path: $definition->path,
                exists: $hasManifest,
                sizeBytes: $size,
                humanSize: $humanSize,
                lastModified: $lastModified,
                description: $definition->description,
            );
        }

        return $reports;
    }

    /**
     * Validate one or all registered manifests for existence and valid JSON syntax.
     *
     * @return array<string, ManifestValidationReport>
     *
     * @throws ManifestNotFoundException
     */
    public function validate(?string $name = null): array
    {
        if (is_string($name) && trim($name) !== '') {
            $definition = $this->registry->get($name);
            if ($definition === null) {
                throw new ManifestNotFoundException($name, "No manifest registered with alias [{$name}].");
            }
            $manifests = [$name => $definition];
        } else {
            $manifests = $this->registry->all();
        }

        $reports = [];

        foreach ($manifests as $manifestName => $definition) {
            $manifest = new Manifest($definition->path, $definition->schema);

            try {
                if (! $manifest->exists()) {
                    $reports[$manifestName] = new ManifestValidationReport(
                        name: $manifestName,
                        filename: basename($definition->path),
                        path: $manifest->path,
                        exists: false,
                        isValid: false,
                        errorMessage: 'File does not exist on disk.',
                    );

                    continue;
                }

                $manifest->fresh();

                $reports[$manifestName] = new ManifestValidationReport(
                    name: $manifestName,
                    filename: basename($definition->path),
                    path: $manifest->path,
                    exists: true,
                    isValid: true,
                );
            } catch (ManifestException $e) {
                $reports[$manifestName] = new ManifestValidationReport(
                    name: $manifestName,
                    filename: basename($definition->path),
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
     * Format validation error messages for display.
     */
    public function formatErrors(ManifestValidationReport $report): string
    {
        if (! empty($report->errors)) {
            $lines = [];
            foreach ($report->errors as $field => $messages) {
                $lines[] = "{$field}: ".implode(', ', $messages);
            }

            return implode("\n", $lines);
        }

        return $report->errorMessage ?? '';
    }
}
