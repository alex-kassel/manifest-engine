<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Filesystem\Filesystem;

class ManifestTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/manifest_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_it_checks_existence(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = Manifest::open($path, files: $this->files);

        $this->assertFalse($manifest->exists());
        $this->files->put($path, '{}');
        $this->assertTrue($manifest->exists());
    }

    public function test_it_initializes_with_schema_defaults(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['version' => 1, 'items' => []];
            }

            public function rules(): array
            {
                return [
                    'version' => ['required', 'integer'],
                    'items' => ['present', 'array'],
                ];
            }
        };

        $manifest = Manifest::open($path, $schema, $this->files);
        $manifest->init();

        $this->assertTrue($manifest->exists());
        $this->assertSame(['version' => 1, 'items' => []], $manifest->load());
    }

    public function test_it_reads_and_writes_dot_notation(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = Manifest::open($path, files: $this->files);

        $manifest->set('app.name', 'MyCoolApp');
        $manifest->set('app.debug', true);
        $manifest->append('app.modules', 'Billing');
        $manifest->append('app.modules', 'Auth');

        $this->assertSame('MyCoolApp', $manifest->get('app.name'));
        $this->assertTrue($manifest->get('app.debug'));
        $this->assertTrue($manifest->has('app.name'));
        $this->assertFalse($manifest->has('app.secret'));
        $this->assertSame(['Billing', 'Auth'], $manifest->get('app.modules'));
    }

    public function test_it_mutates_atomically(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 1]));

        $manifest = Manifest::open($path, files: $this->files);
        $result = $manifest->mutate(function (array $data): array {
            $data['counter']++;

            return $data;
        });

        $this->assertSame(2, $result['counter']);
        $this->assertSame(2, $manifest->get('counter'));
    }

    public function test_it_validates_schema_and_throws_exception_on_invalid_data(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['status' => 'active'];
            }

            public function rules(): array
            {
                return [
                    'status' => ['required', 'in:active,paused'],
                ];
            }
        };

        $manifest = Manifest::open($path, $schema, $this->files);

        $this->expectException(ManifestValidationException::class);
        $manifest->save(['status' => 'invalid_value']);
    }

    public function test_it_exports_json_schema(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $schemaPath = "{$this->tempDir}/schema.json";

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['name' => 'demo'];
            }

            public function rules(): array
            {
                return ['name' => ['required', 'string']];
            }

            public function jsonSchema(): ?array
            {
                return [
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'title' => 'DemoSchema',
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ];
            }
        };

        $manifest = Manifest::open($path, $schema, $this->files);
        $exported = $manifest->exportJsonSchema($schemaPath);

        $this->assertIsArray($exported);
        $this->assertTrue($this->files->exists($schemaPath));
        $this->assertStringContainsString('DemoSchema', (string) $this->files->get($schemaPath));
    }

    public function test_it_throws_when_manifest_not_found_without_schema(): void
    {
        $path = "{$this->tempDir}/missing.json";
        $manifest = Manifest::open($path, files: $this->files);

        $this->expectException(ManifestNotFoundException::class);
        $manifest->load();
    }

    public function test_it_throws_on_malformed_json(): void
    {
        $path = "{$this->tempDir}/bad.json";
        $this->files->put($path, '{not valid json');

        $manifest = Manifest::open($path, files: $this->files);

        $this->expectException(ManifestException::class);
        $manifest->load();
    }
}
