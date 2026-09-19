<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Filesystem\Filesystem;

class ManifestInspectionServiceTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected ManifestManager $manager;

    protected ManifestInspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/inspection_service_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);

        $this->manager = app(ManifestManager::class);
        $this->manager->registry->clear();

        $this->service = new ManifestInspectionService($this->manager);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_get_status_reports(): void
    {
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['title' => 'Sample'];
            }
        };

        $this->manager->registry->register(new ManifestDefinition('sample', "{$this->tempDir}/sample.json", $schema, 'Sample Manifest'));

        // Before file creation
        $reports = $this->service->getStatusReports($this->tempDir);
        $this->assertArrayHasKey('sample', $reports);
        $this->assertFalse($reports['sample']->exists);
        $this->assertNull($reports['sample']->humanSize);

        // After file creation
        $manifest = new Manifest("{$this->tempDir}/sample.json", $schema);
        $manifest->init();

        $refreshed = $this->service->getStatusReports($this->tempDir);
        $this->assertTrue($refreshed['sample']->exists);
        $this->assertNotNull($refreshed['sample']->humanSize);
        $this->assertNotNull($refreshed['sample']->lastModified);
    }

    public function test_validate_all_with_valid_and_invalid_manifests(): void
    {
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['count' => 5];
            }
        };

        $this->manager->registry->register(new ManifestDefinition('metrics', "{$this->tempDir}/metrics.json", $schema));

        // File is missing
        $reports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertFalse($reports['metrics']->exists);
        $this->assertFalse($reports['metrics']->isValid);

        // File is initialized and valid
        $manifest = new Manifest("{$this->tempDir}/metrics.json", $schema);
        $manifest->init();

        $validReports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertTrue($validReports['metrics']->exists);
        $this->assertTrue($validReports['metrics']->isValid);

        // Corrupt file data
        $this->files->put($manifest->path, '{invalid json');
        $invalidReports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertTrue($invalidReports['metrics']->exists);
        $this->assertFalse($invalidReports['metrics']->isValid);
        $this->assertNotNull($invalidReports['metrics']->errorMessage);
        $this->assertStringContainsString('Malformed JSON', $invalidReports['metrics']->formattedErrors());
    }

    public function test_format_bytes(): void
    {
        $this->assertSame('500 B', $this->service->formatBytes(500));
        $this->assertSame('1.5 KB', $this->service->formatBytes(1536));
    }
}
