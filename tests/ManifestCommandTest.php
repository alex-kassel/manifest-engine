<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Facades\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
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
        $this->assertFalse($registry->has('app_registry'));

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

        $registry->register('app_registry', 'app_registry.json', $schema, description: 'Application registry', metadata: ['tag' => 'core']);

        $this->assertTrue($registry->has('app_registry'));
        $def = $registry->get('app_registry');
        $this->assertNotNull($def);
        $this->assertSame('app_registry', $def->name);
        $this->assertSame('app_registry.json', $def->filename);
        $this->assertSame('Application registry', $def->description);
        $this->assertSame(['tag' => 'core'], $def->metadata);
    }

    public function test_manifest_manager_opens_registered_manifest_by_alias(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry()->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['channel' => 'stable', 'version' => 1];
            }

            public function rules(): array
            {
                return [
                    'channel' => ['required', 'string'],
                    'version' => ['required', 'integer'],
                ];
            }
        };

        $manager->register('config', 'config.json', $schema, description: 'Config schema');

        $this->assertTrue($manager->has('config'));

        $manifest = $manager->get('config', $this->tempDir);
        $this->assertSame(['channel' => 'stable', 'version' => 1], $manifest->all());

        $manifest->set('channel', 'beta')->save();
        $this->assertSame('beta', $manifest->get('channel'));

        // Retrieve again via Facade
        $reloaded = Manifest::get('config', $this->tempDir);
        $this->assertSame('beta', $reloaded->get('channel'));
    }

    public function test_manifest_manager_throws_on_unregistered_alias(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);

        $this->expectException(ManifestException::class);
        $manager->get('unregistered_alias');
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
                ['Manifest', 'File', 'Status', 'Size', 'Last Modified', 'Description'],
                [
                    ['demo', 'demo.json', 'Missing', '—', '—', 'Demo schema'],
                ]
            );
    }
}
