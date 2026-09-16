<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

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
        $registry = $manager->registry();
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['tier' => 'pro'];
            }

            public function rules(): array
            {
                return ['tier' => 'required|in:pro,enterprise'];
            }
        };

        $registry->register('service', 'service.json', $schema, description: 'Service manifest');

        // Missing file initially -> should fail validation
        $this->artisan("manifest:validate service --base-path={$this->tempDir}")
            ->assertFailed();

        // Initialize file with valid defaults
        $manifest = $manager->get('service', $this->tempDir);
        $manifest->init();

        $this->artisan("manifest:validate service --base-path={$this->tempDir}")
            ->assertSuccessful();

        // Corrupt with invalid value directly on disk (simulating manual edit)
        $this->files->put($manifest->path, (string) json_encode(['tier' => 'invalid_tier']));

        $this->artisan("manifest:validate service --base-path={$this->tempDir}")
            ->assertFailed();
    }

    public function test_manifest_schema_command_displays_and_exports_json_schema(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $registry = $manager->registry();
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['app_name' => 'Test'];
            }

            public function rules(): array
            {
                return ['app_name' => 'required|string|min:2'];
            }
        };

        $registry->register('app', 'app.json', $schema, description: 'App Schema');

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
        $registry = $manager->registry();
        $registry->clear();

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['name' => 'Scaffolded', 'active' => true];
            }

            public function rules(): array
            {
                return ['name' => 'required|string'];
            }
        };

        $registry->register('scaffold', 'scaffold.json', $schema, description: 'Scaffold demo');

        $this->artisan('manifest:make scaffold')
            ->assertSuccessful();

        $manifest = $manager->get('scaffold');
        $this->assertTrue($manifest->exists());
        $this->assertSame(['name' => 'Scaffolded', 'active' => true], $manifest->all());

        // Clean up
        if ($this->files->exists($manifest->path)) {
            $this->files->delete($manifest->path);
        }
    }

    public function test_manifest_schema_command_handles_missing_argument_fail_safely(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry()->clear();

        // Empty registry scenario
        $this->artisan('manifest:schema', ['--no-interaction' => true])
            ->assertSuccessful();

        // Registered manifests scenario with missing argument
        $manager->registry()->register('test_alias', 'test.json', new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }

            public function rules(): array
            {
                return [];
            }
        });

        $this->artisan('manifest:schema', ['--no-interaction' => true])
            ->expectsOutputToContain('Please specify a manifest name.')
            ->assertFailed();
    }

    public function test_manifest_make_command_handles_missing_argument_fail_safely(): void
    {
        /** @var ManifestManager $manager */
        $manager = app(ManifestManager::class);
        $manager->registry()->clear();

        // Non-interactive without argument should not crash Symfony console
        $this->artisan('manifest:make', ['--no-interaction' => true])
            ->expectsOutputToContain('Please specify a manifest alias name or file path')
            ->assertFailed();
    }
}
