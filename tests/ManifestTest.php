<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

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

    public function test_it_mutates_state_atomically(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 0, 'status' => 'pending']));

        $manifest = Manifest::open($path, files: $this->files);
        $manifest->mutate(function (array $data): array {
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

    public function test_it_throws_manifest_lock_timeout_exception_when_save_is_blocked(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = Manifest::open($path, files: $this->files);
        $manifest->lockTimeoutSeconds = 1;

        $externalLock = Cache::lock($manifest->lockKey(), 10);
        $this->assertTrue($externalLock->acquire());

        try {
            $this->expectException(ManifestLockTimeoutException::class);
            $manifest->save(['status' => 'blocked']);
        } finally {
            $externalLock->release();
        }
    }

    public function test_it_throws_manifest_lock_timeout_exception_when_mutate_is_blocked(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = Manifest::open($path, files: $this->files);
        $manifest->lockTimeoutSeconds = 1;

        $externalLock = Cache::lock($manifest->lockKey(), 10);
        $this->assertTrue($externalLock->acquire());

        try {
            $this->expectException(ManifestLockTimeoutException::class);
            $manifest->mutate(function (array $data): array {
                $data['counter'] = 999;

                return $data;
            });
        } finally {
            $externalLock->release();
        }
    }

    public function test_it_exports_json_schema_definition(): void
    {
        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['name' => 'Demo', 'version' => 1];
            }

            public function jsonSchema(): array
            {
                return [
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'type' => 'object',
                    'required' => ['name', 'version'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 3],
                        'version' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ];
            }
        };

        $manifest = Manifest::open("{$this->tempDir}/manifest.json", $schema, files: $this->files);
        $outputPath = "{$this->tempDir}/manifest.schema.json";
        $exported = $manifest->exportJsonSchema($outputPath);

        $this->assertIsArray($exported);
        $this->assertTrue($this->files->exists($outputPath));
        $content = json_decode((string) $this->files->get($outputPath), true);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $content['$schema']);
        $this->assertSame('string', $content['properties']['name']['type']);
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

    public function test_it_transforms_data_via_callable(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['title' => 'My App']));

        $manifest = Manifest::open($path, files: $this->files);
        $dto = $manifest->toDto(fn (array $data) => (object) ['upper' => strtoupper($data['title'])]);

        $this->assertSame('MY APP', $dto->upper);
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

    public function test_path_detection_and_resolution(): void
    {
        $this->assertTrue(Manifest::isAbsolutePath('/var/log/manifest.json'));
        $this->assertTrue(Manifest::isAbsolutePath('\\Windows\\manifest.json'));
        $this->assertTrue(Manifest::isAbsolutePath('C:\\project\\manifest.json'));
        $this->assertFalse(Manifest::isAbsolutePath('workspace.json'));
        $this->assertFalse(Manifest::isAbsolutePath('sub/nested/workspace.json'));

        // resolvePath returns absolute as-is
        $this->assertSame('/tmp/custom.json', Manifest::resolvePath('/tmp/custom.json'));

        // resolvePath resolves relative against base_path
        $this->assertSame(base_path('manifest.json'), Manifest::resolvePath('manifest.json'));
        $this->assertSame(base_path('sub/nested/manifest.json'), Manifest::resolvePath('sub/nested/manifest.json'));

        // normalizeFilename strips base_path
        $this->assertSame('manifest.json', Manifest::normalizeFilename('manifest.json'));
        $this->assertSame('sub/nested.json', Manifest::normalizeFilename(base_path('sub/nested.json')));
        $this->assertSame('sub/nested.json', Manifest::normalizeFilename('sub\\nested.json'));
    }

    public function test_manifest_open_automatically_resolves_relative_path(): void
    {
        $manifest = Manifest::open('test-relative.json');
        $this->assertSame(base_path('test-relative.json'), $manifest->path);

        $absPath = "{$this->tempDir}/test-absolute.json";
        $manifestAbs = Manifest::open($absPath);
        $this->assertSame($absPath, $manifestAbs->path);
    }

    public function test_manifest_definition_normalizes_filename_and_provides_full_path(): void
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
            filename: base_path('nested/workspace.json'),
            schema: $schema,
        );

        $this->assertSame('nested/workspace.json', $definition->filename);
        $this->assertSame(base_path('nested/workspace.json'), $definition->fullPath());
    }
}
