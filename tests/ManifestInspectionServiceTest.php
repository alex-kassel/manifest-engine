<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

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
        $this->manager->registry()->clear();

        $this->service = new ManifestInspectionService($this->manager, $this->files);
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

            public function rules(): array
            {
                return ['title' => 'required|string'];
            }
        };

        $this->manager->registry()->register('sample', 'sample.json', $schema, 'Sample Manifest');

        // Before file creation
        $reports = $this->service->getStatusReports($this->tempDir);
        $this->assertArrayHasKey('sample', $reports);
        $this->assertFalse($reports['sample']->exists);
        $this->assertNull($reports['sample']->humanSize);

        // After file creation
        $manifest = $this->manager->get('sample', $this->tempDir);
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

            public function rules(): array
            {
                return ['count' => 'required|integer|min:1'];
            }
        };

        $this->manager->registry()->register('metrics', 'metrics.json', $schema);

        // File is missing
        $reports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertFalse($reports['metrics']->exists);
        $this->assertFalse($reports['metrics']->isValid);

        // File is initialized and valid
        $manifest = $this->manager->get('metrics', $this->tempDir);
        $manifest->init();

        $validReports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertTrue($validReports['metrics']->exists);
        $this->assertTrue($validReports['metrics']->isValid);

        // Corrupt file data
        $this->files->put($manifest->path, json_encode(['count' => 0]));
        $invalidReports = $this->service->validateAll('metrics', $this->tempDir);
        $this->assertTrue($invalidReports['metrics']->exists);
        $this->assertFalse($invalidReports['metrics']->isValid);
        $this->assertNotEmpty($invalidReports['metrics']->errors);
        $this->assertStringContainsString('count:', $invalidReports['metrics']->formattedErrors());
    }

    public function test_format_bytes(): void
    {
        $this->assertSame('500 B', $this->service->formatBytes(500));
        $this->assertSame('1.5 KB', $this->service->formatBytes(1536));
    }
}
