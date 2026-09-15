<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Filesystem\Filesystem;

class ManifestCommandTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/manifest_cmd_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_registry_stores_and_retrieves_definitions(): void
    {
        $registry = new ManifestRegistry;
        $this->assertFalse($registry->has('workspace'));

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['key' => 'val'];
            }

            public function rules(): array
            {
                return ['key' => ['required']];
            }
        };

        $registry->register('workspace', 'workspace.json', $schema, description: 'Workspace manifest', metadata: ['tag' => 'core']);

        $this->assertTrue($registry->has('workspace'));
        $def = $registry->get('workspace');
        $this->assertNotNull($def);
        $this->assertSame('workspace', $def->name);
        $this->assertSame('workspace.json', $def->filename);
        $this->assertSame('Workspace manifest', $def->description);
        $this->assertSame(['tag' => 'core'], $def->metadata);
    }

    public function test_manifest_status_command(): void
    {
        /** @var ManifestRegistry $registry */
        $registry = app(ManifestRegistry::class);
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }

            public function rules(): array
            {
                return [];
            }
        };

        $registry->register('demo', 'demo.json', $schema, description: 'Demo schema');

        $this->artisan('manifest:status')
            ->assertSuccessful()
            ->expectsTable(
                ['Manifest', 'File', 'Status', 'Description'],
                [
                    ['demo', 'demo.json', 'Missing', 'Demo schema'],
                ]
            );
    }
}
