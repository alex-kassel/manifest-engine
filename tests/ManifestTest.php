<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Events\ManifestValidationFailed;
use AlexKassel\ManifestEngine\Exceptions\ManifestConcurrentModificationException;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class TestManifestDto implements ManifestDto
{
    public function __construct(
        public string $name,
        public int $version,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            name: (string) ($data['name'] ?? ''),
            version: (int) ($data['version'] ?? 0),
        );
    }
}

class SimpleUserDto
{
    public function __construct(
        public string $username,
        public bool $active = true,
    ) {}
}

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
        $this->assertIsArray($manifest->all());
    }

    public function test_it_caches_data_in_memory_and_reloads_with_fresh(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['title' => 'Initial Title']));

        $manifest = Manifest::open($path, files: $this->files);
        $this->assertSame('Initial Title', $manifest->get('title'));

        // Modify file externally on disk
        $this->files->put($path, json_encode(['title' => 'External Update']));

        // In-memory cache still returns initial value
        $this->assertSame('Initial Title', $manifest->get('title'));

        // Calling fresh() invalidates cache and reads external change
        $manifest->fresh();
        $this->assertSame('External Update', $manifest->get('title'));
    }

    public function test_it_batches_multiple_mutations(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 0, 'status' => 'pending']));

        $manifest = Manifest::open($path, files: $this->files);
        $manifest->batch(function (array $data): array {
            $data['counter'] = 42;
            $data['status'] = 'completed';

            return $data;
        });

        $this->assertSame(42, $manifest->get('counter'));
        $this->assertSame('completed', $manifest->get('status'));
    }

    public function test_it_forgets_keys(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['a' => 1, 'b' => 2]));

        $manifest = Manifest::open($path, files: $this->files);
        $manifest->forget('a');

        $this->assertFalse($manifest->has('a'));
        $this->assertTrue($manifest->has('b'));
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

    public function test_it_auto_compiles_json_schema_from_rules(): void
    {
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['name' => 'Demo', 'version' => 1, 'tags' => ['prod']];
            }

            public function rules(): array
            {
                return [
                    'name' => 'required|string|min:3|max:50',
                    'version' => 'required|integer|min:1',
                    'tags' => 'present|array',
                    'tags.*' => 'string',
                    'status' => 'in:draft,published',
                ];
            }
        };

        $jsonSchema = $schema->jsonSchema();

        $this->assertIsArray($jsonSchema);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $jsonSchema['$schema']);
        $this->assertSame('object', $jsonSchema['type']);
        $this->assertContains('name', $jsonSchema['required']);
        $this->assertContains('version', $jsonSchema['required']);
        $this->assertSame('string', $jsonSchema['properties']['name']['type']);
        $this->assertSame(3, $jsonSchema['properties']['name']['minLength']);
        $this->assertSame(50, $jsonSchema['properties']['name']['maxLength']);
        $this->assertSame('integer', $jsonSchema['properties']['version']['type']);
        $this->assertSame(1, $jsonSchema['properties']['version']['minimum']);
        $this->assertSame('array', $jsonSchema['properties']['tags']['type']);
        $this->assertSame('string', $jsonSchema['properties']['tags']['items']['type']);
        $this->assertSame(['draft', 'published'], $jsonSchema['properties']['status']['enum']);
    }

    public function test_it_hydrates_and_saves_dto_contract(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['name' => 'Analytics', 'version' => 2]));

        $manifest = Manifest::open($path, files: $this->files);

        $dto = $manifest->toDto(TestManifestDto::class);
        $this->assertInstanceOf(TestManifestDto::class, $dto);
        $this->assertSame('Analytics', $dto->name);
        $this->assertSame(2, $dto->version);

        $dto->name = 'Analytics V3';
        $dto->version = 3;
        $manifest->saveDto($dto);

        $this->assertSame('Analytics V3', $manifest->get('name'));
        $this->assertSame(3, $manifest->get('version'));
    }

    public function test_it_hydrates_dto_with_constructor_promotion(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['username' => 'alex', 'active' => true]));

        $manifest = Manifest::open($path, files: $this->files);
        $dto = $manifest->toDto(SimpleUserDto::class);

        $this->assertInstanceOf(SimpleUserDto::class, $dto);
        $this->assertSame('alex', $dto->username);
        $this->assertTrue($dto->active);
    }

    public function test_it_dispatches_lifecycle_events(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['key' => 'old']));

        $dispatcher = new Dispatcher;
        $eventsDispatched = [];

        $dispatcher->listen(ManifestOpened::class, function () use (&$eventsDispatched) {
            $eventsDispatched[] = 'opened';
        });
        $dispatcher->listen(ManifestSaving::class, function () use (&$eventsDispatched) {
            $eventsDispatched[] = 'saving';
        });
        $dispatcher->listen(ManifestSaved::class, function () use (&$eventsDispatched) {
            $eventsDispatched[] = 'saved';
        });
        $dispatcher->listen(ManifestMutated::class, function () use (&$eventsDispatched) {
            $eventsDispatched[] = 'mutated';
        });
        $dispatcher->listen(ManifestValidationFailed::class, function () use (&$eventsDispatched) {
            $eventsDispatched[] = 'validation_failed';
        });

        $manifest = Manifest::open($path, files: $this->files, events: $dispatcher);
        $this->assertContains('opened', $eventsDispatched);

        $manifest->mutate(function (array $data): array {
            $data['key'] = 'new';

            return $data;
        });
        $this->assertContains('mutated', $eventsDispatched);

        $manifest->save(['key' => 'manual_save']);
        $this->assertContains('saving', $eventsDispatched);
        $this->assertContains('saved', $eventsDispatched);
    }

    public function test_it_captures_snapshot_and_rolls_back(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['stage' => 'stable']));

        $manifest = Manifest::open($path, files: $this->files);
        $manifest->snapshot();

        $manifest->set('stage', 'broken');
        $this->assertSame('broken', $manifest->get('stage'));

        $manifest->rollback();
        $this->assertSame('stable', $manifest->get('stage'));
    }

    public function test_it_auto_rolls_back_memory_cache_on_mutation_exception(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 10]));

        $manifest = Manifest::open($path, files: $this->files);

        try {
            $manifest->mutate(function (array $data): array {
                $data['counter'] = 999;
                throw new RuntimeException('Intentional crash');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // Memory cache must still be 10, not 999
        $this->assertSame(10, $manifest->get('counter'));
    }

    public function test_it_saves_optimistically_and_throws_on_conflict(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['version' => 1]));

        $manifest = Manifest::open($path, files: $this->files);
        $this->assertSame(1, $manifest->get('version'));

        // Modify file externally (simulating another agent or git pull)
        $this->files->put($path, json_encode(['version' => 2]));

        $this->expectException(ManifestConcurrentModificationException::class);
        $manifest->saveOptimistic(['version' => 3]);
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
