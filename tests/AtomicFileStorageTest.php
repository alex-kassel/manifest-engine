<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use Illuminate\Filesystem\Filesystem;

class AtomicFileStorageTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected AtomicFileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/storage_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
        $this->storage = new AtomicFileStorage($this->files);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_exists_and_hash(): void
    {
        $path = "{$this->tempDir}/test.json";
        $this->assertFalse($this->storage->exists($path));
        $this->assertNull($this->storage->hash($path));

        $this->storage->writeAtomic($path, "{\"ok\":true}\n");
        $this->assertTrue($this->storage->exists($path));
        $this->assertSame(sha1("{\"ok\":true}\n"), $this->storage->hash($path));
    }

    public function test_read_locked(): void
    {
        $path = "{$this->tempDir}/test.json";
        $this->assertSame('', $this->storage->readLocked($path));

        $this->storage->writeAtomic($path, 'hello world');
        $this->assertSame('hello world', $this->storage->readLocked($path));
    }

    public function test_mutate_locked(): void
    {
        $path = "{$this->tempDir}/counter.txt";
        $this->storage->writeAtomic($path, '10');

        $result = $this->storage->mutateLocked($path, function (string $content): string {
            return (string) (((int) $content) + 5);
        });

        $this->assertSame('15', $result);
        $this->assertSame('15', $this->storage->readLocked($path));
    }

    public function test_lock_path_resolution(): void
    {
        $path = "{$this->tempDir}/doc.json";
        $lockPath = $this->storage->lockPath($path);

        $this->assertSame($this->tempDir.'/.doc.json.lock', $lockPath);
    }
}
