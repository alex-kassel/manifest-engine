<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

class ManifestTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/manifest_engine_test_'.uniqid();
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
        $manifest = new Manifest($path);

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
        };

        $manifest = new Manifest($path, $schema);
        $data = $manifest->init();

        $this->assertTrue($manifest->exists());
        $this->assertSame(['version' => 1, 'items' => []], $data);
        $this->assertSame(1, $manifest->get('version'));
    }

    public function test_it_reads_and_writes_dot_notation(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = new Manifest($path);

        $manifest->set('app.name', 'MyCoolApp');
        $manifest->set('app.debug', true);
        $manifest->append('app.features', 'billing');
        $manifest->append('app.features', 'auth');

        $this->assertSame('MyCoolApp', $manifest->get('app.name'));
        $this->assertTrue($manifest->get('app.debug'));
        $this->assertSame(['billing', 'auth'], $manifest->get('app.features'));
        $this->assertTrue($manifest->has('app.name'));
        $this->assertFalse($manifest->has('app.missing'));
    }

    public function test_it_caches_data_in_memory_and_reloads_with_fresh(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['title' => 'Initial Title']));

        $manifest = new Manifest($path);
        $this->assertSame('Initial Title', $manifest->get('title'));

        // Modify file externally on disk
        $this->files->put($path, json_encode(['title' => 'External Edit']));

        // Cached in-memory read still sees old value
        $this->assertSame('Initial Title', $manifest->get('title'));

        // fresh() reads latest from disk and returns fresh array
        $freshData = $manifest->fresh();
        $this->assertSame('External Edit', $freshData['title']);
        $this->assertSame('External Edit', $manifest->get('title'));
    }

    public function test_it_mutates_state_atomically(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 0, 'status' => 'pending']));

        $manifest = new Manifest($path);
        $mutated = $manifest->mutate(function (array $data): array {
            $data['counter'] = 42;
            $data['status'] = 'completed';

            return $data;
        });

        $this->assertSame(['counter' => 42, 'status' => 'completed'], $mutated);
        $this->assertSame(42, $manifest->get('counter'));
        $this->assertSame('completed', $manifest->get('status'));
    }

    public function test_it_forgets_keys(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['a' => 1, 'b' => 2]));

        $manifest = new Manifest($path);
        $manifest->forget('a');

        $this->assertFalse($manifest->has('a'));
        $this->assertTrue($manifest->has('b'));
    }

    public function test_it_throws_manifest_lock_timeout_exception_when_save_is_blocked(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = new Manifest($path);
        $manifest->lockTimeoutSeconds = 1;

        $externalLock = Cache::lock($manifest->lockKey(), 10);
        $this->assertTrue($externalLock->acquire());

        try {
            $this->expectException(ManifestLockTimeoutException::class);
            $manifest->save(['blocked' => true]);
        } finally {
            $externalLock->release();
        }
    }

    public function test_it_throws_manifest_lock_timeout_exception_when_mutate_is_blocked(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = new Manifest($path);
        $manifest->lockTimeoutSeconds = 1;

        $externalLock = Cache::lock($manifest->lockKey(), 10);
        $this->assertTrue($externalLock->acquire());

        try {
            $this->expectException(ManifestLockTimeoutException::class);
            $manifest->mutate(fn (array $d) => array_merge($d, ['x' => 1]));
        } finally {
            $externalLock->release();
        }
    }

    public function test_it_mutates_dto_contract(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['name' => 'Analytics', 'version' => 2]));

        $manifest = new Manifest($path);

        $dto = $manifest->mutateDto(TestManifestDto::class, function (TestManifestDto $dto): TestManifestDto {
            $this->assertSame('Analytics', $dto->name);
            $this->assertSame(2, $dto->version);

            $dto->name = 'Analytics V3';
            $dto->version = 3;

            return $dto;
        });

        $this->assertInstanceOf(TestManifestDto::class, $dto);
        $this->assertSame('Analytics V3', $dto->name);
        $this->assertSame(3, $dto->version);
        $this->assertSame('Analytics V3', $manifest->get('name'));
    }

    public function test_to_dto_throws_if_class_does_not_implement_manifest_dto(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['title' => 'My App']));

        $manifest = new Manifest($path);

        $this->expectException(\Error::class);
        $manifest->toDto(\stdClass::class);
    }

    public function test_it_throws_when_manifest_not_found_without_schema(): void
    {
        $path = "{$this->tempDir}/missing.json";
        $manifest = new Manifest($path);

        $this->expectException(ManifestNotFoundException::class);
        $manifest->load();
    }

    public function test_it_throws_on_malformed_json(): void
    {
        $path = "{$this->tempDir}/bad.json";
        $this->files->put($path, '{not valid json');

        $manifest = new Manifest($path);

        $this->expectException(ManifestException::class);
        $manifest->load();
    }

    public function test_manifest_definition_holds_path_and_schema(): void
    {
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }
        };

        $definition = new ManifestDefinition(
            name: 'workspace',
            path: base_path('nested/workspace.json'),
            schema: $schema,
        );

        $this->assertSame(base_path('nested/workspace.json'), $definition->path);
        $this->assertSame($schema, $definition->schema);
    }

    public function test_it_implements_arrayable_and_jsonable_and_json_serializable(): void
    {
        $path = "{$this->tempDir}/contracts.json";
        $data = ['name' => 'Demo', 'active' => true];
        $this->files->put($path, json_encode($data));

        $manifest = new Manifest($path);

        // Arrayable
        $this->assertSame($data, $manifest->toArray());

        // Jsonable
        $this->assertSame(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $manifest->toJson());

        // JsonSerializable
        $this->assertSame(json_encode($data), json_encode($manifest));
    }

    public function test_it_saves_data(): void
    {
        $path = "{$this->tempDir}/save.json";
        $manifest = new Manifest($path);

        $manifest->save(['from_array' => 'yes']);

        $this->assertSame('yes', $manifest->get('from_array'));
        $this->assertSame('yes', $manifest->fresh()['from_array']);
    }

    public function test_it_mutates_dto_in_place_when_mutator_returns_void(): void
    {
        $path = "{$this->tempDir}/manifest_inplace.json";
        $this->files->put($path, json_encode(['name' => 'Original', 'version' => 1]));

        $manifest = new Manifest($path);

        $dto = $manifest->mutateDto(TestManifestDto::class, function (TestManifestDto $dto): void {
            $dto->name = 'Mutated In Place';
            $dto->version = 99;
        });

        $this->assertSame('Mutated In Place', $dto->name);
        $this->assertSame(99, $dto->version);
        $this->assertSame('Mutated In Place', $manifest->get('name'));
        $this->assertSame(99, $manifest->get('version'));
    }

    public function test_manifest_manager_fluent_register_and_open(): void
    {
        $registry = new ManifestRegistry;
        $manager = new ManifestManager($registry);
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['app' => 'Test'];
            }
        };

        $this->assertSame($registry, $manager->registry);

        // Fluent registration chaining
        $chainResult = $manager
            ->register(new ManifestDefinition('app', base_path('app.json'), $schema))
            ->register(new ManifestDefinition('db', base_path('db.json'), $schema));

        $this->assertSame($manager, $chainResult);
        $this->assertTrue($registry->has('app'));
        $this->assertTrue($registry->has('db'));

        // Open registered alias
        $appManifest = $manager->open('app');
        $this->assertSame(base_path('app.json'), $appManifest->path);

        // Opening unregistered alias throws ManifestNotFoundException
        $this->expectException(ManifestNotFoundException::class);
        $manager->open('non_existent');
    }

    public function test_it_saves_arrayable_dto_directly(): void
    {
        $path = "{$this->tempDir}/dto-save.json";
        $manifest = new Manifest($path);
        $dto = new TestManifestDto(name: 'dto-saved', version: 42);

        $saved = $manifest->save($dto);

        $this->assertSame(['name' => 'dto-saved', 'version' => 42], $saved);
        $this->assertSame('dto-saved', $manifest->get('name'));
        $this->assertSame(42, $manifest->get('version'));
    }

    public function test_it_invalidates_in_memory_cached_data(): void
    {
        $path = "{$this->tempDir}/cache.json";
        $manifest = new Manifest($path);
        $manifest->save(['counter' => 1]);

        $this->assertSame(1, $manifest->get('counter'));

        // External update bypassing instance
        $this->files->put($path, json_encode(['counter' => 999]));
        $this->assertSame(1, $manifest->get('counter')); // Still memory cached

        $manifest->invalidate();
        $this->assertSame(999, $manifest->get('counter')); // Reloaded fresh
    }

    public function test_it_exports_json_schema(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $exportPath = "{$this->tempDir}/schema.json";
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }

            public function jsonSchema(): array
            {
                return [
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'title' => 'SampleSchema',
                ];
            }
        };

        $manifest = new Manifest($path, $schema);
        $exported = $manifest->exportJsonSchema($exportPath);

        $this->assertSame('SampleSchema', $exported['title']);
        $this->assertTrue($this->files->exists($exportPath));
        $this->assertStringContainsString('SampleSchema', $this->files->get($exportPath));
    }
}

class TestManifestDto implements ManifestDto
{
    public function __construct(
        public string $name = '',
        public int $version = 1,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            name: (string) ($data['name'] ?? ''),
            version: (int) ($data['version'] ?? 1),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
