<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use Illuminate\Filesystem\Filesystem;

class LockDirectoryTest extends TestCase
{
    protected string $manifestDir;

    protected string $locksDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->manifestDir = sys_get_temp_dir().'/manifest_dir_'.uniqid();
        $this->locksDir = sys_get_temp_dir().'/locks_dir_'.uniqid();
        $this->files->ensureDirectoryExists($this->manifestDir);
        $this->files->ensureDirectoryExists($this->locksDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->manifestDir)) {
            $this->files->deleteDirectory($this->manifestDir);
        }
        if ($this->files->isDirectory($this->locksDir)) {
            $this->files->deleteDirectory($this->locksDir);
        }
        parent::tearDown();
    }

    public function test_lock_files_are_isolated_and_never_created_in_manifest_folder(): void
    {
        $storage = new AtomicFileStorage(
            files: $this->files,
            locksDirectory: $this->locksDir
        );

        $manifestPath = "{$this->manifestDir}/workspace.json";
        $manifest = Manifest::open(
            path: $manifestPath,
            storage: $storage,
            files: $this->files
        );

        // Perform mutation that acquires lock
        $manifest->mutate(function (array $data): array {
            $data['env'] = 'testing';

            return $data;
        });

        // Verify the lock file is NOT in the manifest directory
        $manifestDirFiles = $this->files->files($this->manifestDir);
        $manifestFileNames = array_map(fn ($f) => $f->getFilename(), $manifestDirFiles);

        $this->assertContains('workspace.json', $manifestFileNames);
        $this->assertNotContains('.workspace.json.lock', $manifestFileNames);
        $this->assertNotContains('workspace.json.lock', $manifestFileNames);

        // Verify the lock file IS in the dedicated locks directory
        $lockFiles = $this->files->files($this->locksDir);
        $this->assertCount(1, $lockFiles);
        $this->assertStringEndsWith('.lock', $lockFiles[0]->getFilename());
    }
}
