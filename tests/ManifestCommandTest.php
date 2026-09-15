<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use AlexKassel\ManifestEngine\Services\ManifestInstaller;
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

        $registry->register('workspace', 'workspace.json', $schema, description: 'Workspace manifest');

        $this->assertTrue($registry->has('workspace'));
        $def = $registry->get('workspace');
        $this->assertNotNull($def);
        $this->assertSame('workspace', $def->name);
        $this->assertSame('workspace.json', $def->filename);
        $this->assertSame('Workspace manifest', $def->description);
    }

    public function test_manifest_install_command(): void
    {
        $srcDir = "{$this->tempDir}/src";
        $this->files->ensureDirectoryExists($srcDir);
        $dummyRunner = "{$srcDir}/source_runner";
        $this->files->put($dummyRunner, "#!/usr/bin/env php\n<?php echo 'runner';\n");

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['installed' => true];
            }

            public function rules(): array
            {
                return ['installed' => ['required', 'boolean']];
            }
        };

        /** @var ManifestRegistry $registry */
        $registry = app(ManifestRegistry::class);
        $registry->clear();
        $registry->register('test-pkg', 'test-pkg.json', $schema, runnerPath: $dummyRunner);

        $installer = app(ManifestInstaller::class);
        $def = $registry->get('test-pkg');
        $this->assertNotNull($def);

        $steps = $installer->install($def, $this->tempDir);
        $this->assertCount(2, $steps);
        $this->assertSame('created', $steps[0]['status']);
        $this->assertSame('created', $steps[1]['status']);

        $this->assertTrue($this->files->exists("{$this->tempDir}/test-pkg.json"));
        $this->assertTrue($this->files->exists("{$this->tempDir}/source_runner"));
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
                ['Manifest', 'File', 'Status', 'Standalone Runner', 'Description'],
                [
                    ['demo', 'demo.json', 'Missing', '—', 'Demo schema'],
                ]
            );
    }
}
