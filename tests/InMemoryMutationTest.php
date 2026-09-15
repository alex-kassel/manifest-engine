<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Manifest;
use Illuminate\Filesystem\Filesystem;

class InMemoryMutationTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/in_memory_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_it_mutates_in_memory_and_tracks_dirty_flag(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $manifest = Manifest::open($path, files: $this->files);

        $this->assertFalse($manifest->isDirty());

        $manifest->set('app.name', 'Acme');
        $manifest->set('app.debug', true);
        $manifest->append('app.features', 'billing');
        $manifest->append('app.features', 'auth');

        $this->assertTrue($manifest->isDirty());
        // File should not exist on disk yet
        $this->assertFalse($this->files->exists($path));

        // Read from in-memory state
        $this->assertSame('Acme', $manifest->get('app.name'));
        $this->assertTrue($manifest->get('app.debug'));
        $this->assertSame(['billing', 'auth'], $manifest->get('app.features'));

        // Save to disk
        $manifest->save();

        $this->assertFalse($manifest->isDirty());
        $this->assertTrue($this->files->exists($path));

        // Another instance reads persisted data
        $reloaded = Manifest::open($path, files: $this->files);
        $this->assertSame('Acme', $reloaded->get('app.name'));
        $this->assertSame(['billing', 'auth'], $reloaded->get('app.features'));
    }

    public function test_it_forgets_in_memory_until_saved(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['a' => 1, 'b' => 2]));

        $manifest = Manifest::open($path, files: $this->files);
        $this->assertFalse($manifest->isDirty());

        $manifest->forget('a');
        $this->assertTrue($manifest->isDirty());
        $this->assertFalse($manifest->has('a'));
        $this->assertTrue($manifest->has('b'));

        // External file on disk still has 'a' until saved
        $raw = json_decode((string) $this->files->get($path), true);
        $this->assertArrayHasKey('a', $raw);

        $manifest->save();
        $this->assertFalse($manifest->isDirty());

        $rawAfter = json_decode((string) $this->files->get($path), true);
        $this->assertArrayNotHasKey('a', $rawAfter);
    }

    public function test_it_auto_invalidates_cache_when_file_mtime_changes_externally(): void
    {
        $path = "{$this->tempDir}/manifest.json";
        $this->files->put($path, json_encode(['counter' => 1]));

        $manifest = Manifest::open($path, files: $this->files);
        $this->assertSame(1, $manifest->get('counter'));

        // Simulate external edit with changed mtime
        sleep(1);
        $this->files->put($path, json_encode(['counter' => 99]));

        // Without calling fresh(), get() should detect mtime change and reload
        $this->assertSame(99, $manifest->get('counter'));
    }
}
