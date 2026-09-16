<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use Illuminate\Contracts\Cache\LockProvider;
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
        $customLocksDir = "{$this->tempDir}/custom-locks";
        $storage = new AtomicFileStorage($this->files, locksDirectory: $customLocksDir);

        $path = "{$this->tempDir}/doc.json";
        $lockPath = $storage->lockPath($path);

        $canonicalDir = realpath(dirname($path)) ?: dirname($path);
        $expectedCanonical = rtrim($canonicalDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($path);
        $expectedHash = hash('sha256', $expectedCanonical);

        $canonicalLocksDir = realpath($customLocksDir) ?: $customLocksDir;
        $canonicalLockPath = (realpath(dirname($lockPath)) ?: dirname($lockPath)).DIRECTORY_SEPARATOR.basename($lockPath);

        $this->assertSame($canonicalLocksDir.DIRECTORY_SEPARATOR.$expectedHash.'.lock', $canonicalLockPath);
        $this->assertNotSame($this->tempDir.'/.doc.json.lock', $lockPath);

        // Verify lock path remains 100% identical after file is physically created
        $this->files->put($path, "{\"created\":true}\n");
        $this->assertSame($lockPath, $storage->lockPath($path));
    }

    public function test_mutate_locked_with_cache_lock_provider(): void
    {
        /** @var LockProvider $lockProvider */
        $lockProvider = app('cache')->store()->getStore();
        $storage = new AtomicFileStorage($this->files, lockProvider: $lockProvider);

        $path = "{$this->tempDir}/cache_counter.txt";
        $storage->writeAtomic($path, '100');

        $result = $storage->mutateLocked($path, function (string $content): string {
            return (string) (((int) $content) + 50);
        });

        $this->assertSame('150', $result);
        $this->assertSame('150', $storage->readLocked($path));
    }

    public function test_lock_timeout_throws_exception_when_file_is_locked(): void
    {
        $customLocksDir = "{$this->tempDir}/timeout-locks";
        $storage = new class($this->files, $customLocksDir) extends AtomicFileStorage
        {
            public const DEFAULT_LOCK_TIMEOUT_SECONDS = 1;

            public const DEFAULT_SLEEP_MICROSECONDS = 1000;
        };

        $path = "{$this->tempDir}/locked_file.json";
        $lockPath = $storage->lockPath($path);
        $this->files->ensureDirectoryExists(dirname($lockPath));

        $fp = fopen($lockPath, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX));

        try {
            $this->expectException(ManifestLockTimeoutException::class);
            $storage->mutateLocked($path, function (string $content): string {
                return 'mutated';
            });
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
