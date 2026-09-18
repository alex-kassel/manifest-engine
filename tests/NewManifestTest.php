<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\NewManifest;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Filesystem\Filesystem;

class NewManifestTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/new_manifest_test_'.bin2hex(random_bytes(6));
        $this->files->makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->files->exists($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_it_checks_existence_and_calculates_hash(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = new NewManifest($path);

        $this->assertFalse($manifest->exists());
        $this->assertNull($manifest->hash());

        $this->files->put($path, '{"key": "value"}');

        $this->assertTrue($manifest->exists());
        $this->assertSame(sha1('{"key": "value"}'), $manifest->hash());
    }

    public function test_it_throws_when_file_not_found_without_schema(): void
    {
        $path = "{$this->tempDir}/missing.json";
        $manifest = new NewManifest($path);

        $this->expectException(ManifestNotFoundException::class);
        $manifest->load();
    }

    public function test_it_initializes_with_schema_defaults(): void
    {
        $path = "{$this->tempDir}/schema_init.json";
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['app' => 'Demo', 'active' => true];
            }
        };

        $manifest = new NewManifest($path, $schema);
        $this->assertFalse($manifest->exists());

        $data = $manifest->init();
        $this->assertSame(['app' => 'Demo', 'active' => true], $data);
        $this->assertTrue($manifest->exists());
    }

    public function test_it_mutates_atomically_and_returns_updated_data(): void
    {
        $path = "{$this->tempDir}/mutate.json";
        $manifest = new NewManifest($path);

        // set
        $data = $manifest->set('name', 'Alex');
        $this->assertSame(['name' => 'Alex'], $data);
        $this->assertSame('Alex', $manifest->get('name'));

        // append
        $data = $manifest->append('tags', 'php');
        $data = $manifest->append('tags', 'laravel');
        $this->assertSame(['name' => 'Alex', 'tags' => ['php', 'laravel']], $data);
        $this->assertTrue($manifest->has('tags'));

        // forget
        $data = $manifest->forget('name');
        $this->assertFalse($manifest->has('name'));
        $this->assertSame(['tags' => ['php', 'laravel']], $data);

        // fresh reload
        $this->assertSame(['tags' => ['php', 'laravel']], $manifest->fresh());
        $this->assertSame(['tags' => ['php', 'laravel']], $manifest->all());
    }

    public function test_it_resolves_schema_automatically_from_registry(): void
    {
        $path = "{$this->tempDir}/registry_auto.json";
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['from_registry' => true];
            }
        };

        $registry = app(ManifestRegistry::class);
        $registry->register(new ManifestDefinition('auto', $path, $schema));

        // Schema is not passed explicitly; NewManifest resolves it from registry by path
        $manifest = new NewManifest($path);
        $this->assertSame($schema, $manifest->schema);

        $data = $manifest->init();
        $this->assertSame(['from_registry' => true], $data);
    }

    public function test_it_works_with_typed_dtos(): void
    {
        $path = "{$this->tempDir}/dto.json";
        $manifest = new NewManifest($path);
        $manifest->set('name', 'Initial');
        $manifest->set('count', 1);

        $dtoClass = new class implements ManifestDto
        {
            public string $name = '';

            public int $count = 0;

            public static function fromArray(array $data): static
            {
                $self = new self;
                $self->name = (string) ($data['name'] ?? '');
                $self->count = (int) ($data['count'] ?? 0);

                return $self;
            }

            public function toArray(): array
            {
                return ['name' => $this->name, 'count' => $this->count];
            }
        };

        $loadedDto = $manifest->toDto($dtoClass::class);
        $this->assertSame('Initial', $loadedDto->name);
        $this->assertSame(1, $loadedDto->count);

        $mutatedDto = $manifest->mutateDto($dtoClass::class, function ($dto) {
            $dto->name = 'Updated';
            $dto->count = 42;
        });

        $this->assertSame('Updated', $mutatedDto->name);
        $this->assertSame(42, $mutatedDto->count);
        $this->assertSame('Updated', $manifest->get('name'));
        $this->assertSame(42, $manifest->get('count'));
    }
}
