<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Filesystem\Filesystem;

class ConsoleCommandsTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/console_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_manifest_validate_command_reports_valid_and_invalid_files(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        /** @var ManifestRegistry $registry */
        $registry = $manager->registry;
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['tier' => 'pro'];
            }
        };

        $registry->register(new ManifestDefinition('service', "{$this->tempDir}/service.json", $schema, description: 'Service manifest'));

        // Missing file initially -> should fail validation
        $this->artisan('manifest:validate service')
            ->assertFailed();

        // Initialize file with valid defaults
        $manifest = new Manifest("{$this->tempDir}/service.json", $schema);
        $manifest->init();

        $this->artisan('manifest:validate service')
            ->assertSuccessful();

        // Corrupt with malformed JSON directly on disk
        $this->files->put($manifest->path, '{broken-json');

        $this->artisan('manifest:validate service')
            ->assertFailed();
    }

    public function test_manifest_schema_command_displays_and_exports_json_schema(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $registry = $manager->registry;
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['app_name' => 'Test'];
            }

            public function jsonSchema(): array
            {
                return [
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'type' => 'object',
                    'properties' => [
                        'app_name' => ['type' => 'string'],
                    ],
                ];
            }
        };

        $registry->register(new ManifestDefinition('app', base_path('app.json'), $schema, description: 'App Schema'));

        $outputPath = "{$this->tempDir}/app.schema.json";

        $this->artisan('manifest:schema app --output='.$outputPath)
            ->assertSuccessful();

        $this->assertTrue($this->files->exists($outputPath));
        $content = json_decode((string) $this->files->get($outputPath), true);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $content['$schema']);
        $this->assertSame('string', $content['properties']['app_name']['type']);
    }

    public function test_manifest_make_command_scaffolds_file_with_defaults(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $registry = $manager->registry;
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['name' => 'Scaffolded', 'active' => true];
            }
        };

        $registry->register(new ManifestDefinition('scaffold', base_path('scaffold.json'), $schema, description: 'Scaffold demo'));

        $scaffoldPath = base_path('scaffold.json');
        if ($this->files->exists($scaffoldPath)) {
            $this->files->delete($scaffoldPath);
        }

        try {
            $this->artisan('manifest:init scaffold')
                ->assertSuccessful();

            $manifest = $manager->open('scaffold');
            $this->assertTrue($manifest->exists());
            $this->assertSame(['name' => 'Scaffolded', 'active' => true], $manifest->all());
        } finally {
            if ($this->files->exists($scaffoldPath)) {
                $this->files->delete($scaffoldPath);
            }
        }
    }

    public function test_manifest_init_command_supports_absolute_custom_path(): void
    {
        $absolutePath = "{$this->tempDir}/absolute_custom.json";

        $this->artisan("manifest:init {$absolutePath}")
            ->assertSuccessful();

        $this->assertTrue($this->files->exists($absolutePath));
    }

    public function test_manifest_schema_command_handles_missing_argument_fail_safely(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry->clear();

        // Empty registry scenario
        $this->artisan('manifest:schema', ['--no-interaction' => true])
            ->assertSuccessful();

        // Registered manifests scenario with missing argument
        $manager->registry->register(new ManifestDefinition('test_alias', base_path('test.json'), new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }
        }));

        $this->artisan('manifest:schema', ['--no-interaction' => true])
            ->expectsOutputToContain('Please specify a manifest name.')
            ->assertFailed();
    }

    public function test_manifest_init_command_handles_missing_argument_fail_safely(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry->clear();

        // Non-interactive without argument should not crash Symfony console
        $this->artisan('manifest:init', ['--no-interaction' => true])
            ->expectsOutputToContain('Please specify a manifest alias name or file path')
            ->assertFailed();
    }

    public function test_manifest_init_command_rejects_path_traversal(): void
    {
        $this->artisan('manifest:init', ['name' => '../../evil.json', '--no-interaction' => true])
            ->expectsOutputToContain('Path traversal is not allowed in manifest path.')
            ->assertFailed();
    }

    public function test_manifest_init_command_force_overwrites_existing_file(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['v' => 2];
            }
        };

        $manager->registry->register(new ManifestDefinition('force_test', base_path('force_test.json'), $schema));
        $manifest = $manager->open('force_test');

        // Create initial
        $this->files->put($manifest->path, json_encode(['v' => 1]));

        // Fail without force
        $this->artisan('manifest:init force_test')
            ->assertFailed();

        // Succeed with force
        $this->artisan('manifest:init force_test --force')
            ->assertSuccessful();

        $this->assertSame(['v' => 2], $manifest->fresh());

        if ($this->files->exists($manifest->path)) {
            $this->files->delete($manifest->path);
        }
    }

    public function test_manifest_status_command_displays_table(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry->clear();

        $this->artisan('manifest:status')
            ->expectsOutputToContain('No manifest definitions are registered in this application.')
            ->assertSuccessful();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['status' => 'ok'];
            }
        };

        $manager->registry->register(new ManifestDefinition('app_status', base_path('app_status.json'), $schema, description: 'Test desc'));

        $this->artisan('manifest:status')
            ->assertSuccessful();
    }

    public function test_manifest_status_command_supports_base_path_option(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['status' => 'ok'];
            }
        };

        $manager->registry->register(new ManifestDefinition('custom_status', "{$this->tempDir}/custom.json", $schema));

        $this->files->put("{$this->tempDir}/custom.json", json_encode(['status' => 'ok']));

        $this->artisan('manifest:status')
            ->assertSuccessful();
    }
}
