<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestDocument;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class ManifestDocumentTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/manifest_document_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_document_pure_in_memory_operations(): void
    {
        $doc = new ManifestDocument(['name' => 'MyApp', 'tags' => ['php']]);

        $this->assertFalse($doc->isDirty());
        $this->assertSame('MyApp', $doc->get('name'));
        $this->assertTrue($doc->has('tags'));

        $doc->set('version', '1.0.0');
        $doc->push('tags', 'laravel');
        $doc->set('settings.theme', 'dark');

        $this->assertTrue($doc->isDirty());
        $this->assertSame('1.0.0', $doc->get('version'));
        $this->assertSame(['php', 'laravel'], $doc->get('tags'));
        $this->assertSame('dark', $doc->get('settings.theme'));

        // ArrayAccess interface
        $this->assertSame('MyApp', $doc['name']);
        $doc['license'] = 'MIT';
        $this->assertSame('MIT', $doc->get('license'));
        $this->assertTrue(isset($doc['license']));
        unset($doc['license']);
        $this->assertFalse($doc->has('license'));

        // Reset dirty
        $doc->resetDirty();
        $this->assertFalse($doc->isDirty());
        $this->assertSame('1.0.0', $doc->getOriginal()['version']);
    }

    public function test_manifest_read_and_write_flow(): void
    {
        $path = "{$this->tempDir}/app.json";
        $manifest = Manifest::open($path, files: $this->files);

        // Write initial document
        $doc = new ManifestDocument(['app' => 'Alpha', 'counter' => 1]);
        $manifest->write($doc);

        $this->assertTrue($manifest->exists());

        // Read into in-memory document
        $loadedDoc = $manifest->read();
        $this->assertInstanceOf(ManifestDocument::class, $loadedDoc);
        $this->assertSame('Alpha', $loadedDoc->get('app'));
        $this->assertSame(1, $loadedDoc->get('counter'));

        // Modify in-memory
        $loadedDoc->set('counter', 2);
        $manifest->write($loadedDoc);

        // Re-read to assert persistence
        $refreshedDoc = $manifest->read();
        $this->assertSame(2, $refreshedDoc->get('counter'));
    }

    public function test_manifest_transaction_batches_loop_mutations_into_single_disk_write(): void
    {
        $path = "{$this->tempDir}/packages.json";
        $manifest = Manifest::open($path, files: $this->files);

        $packages = [
            ['name' => 'pkg-1', 'ver' => '1.0'],
            ['name' => 'pkg-2', 'ver' => '2.0'],
            ['name' => 'pkg-3', 'ver' => '3.0'],
        ];

        // Execute transaction over intensive loop
        $doc = $manifest->transaction(function (ManifestDocument $d) use ($packages): void {
            foreach ($packages as $pkg) {
                $d->set("packages.{$pkg['name']}", $pkg['ver']);
                $d->push('log', "Added {$pkg['name']}");
            }
        });

        $this->assertInstanceOf(ManifestDocument::class, $doc);
        $this->assertSame('1.0', $doc->get('packages.pkg-1'));
        $this->assertSame('2.0', $doc->get('packages.pkg-2'));
        $this->assertSame('3.0', $doc->get('packages.pkg-3'));
        $this->assertCount(3, $doc->get('log'));

        // Verify disk content directly
        $onDisk = json_decode((string) $this->files->get($path), true);
        $this->assertSame('1.0', $onDisk['packages']['pkg-1']);
        $this->assertSame('3.0', $onDisk['packages']['pkg-3']);
        $this->assertCount(3, $onDisk['log']);
    }

    public function test_manifest_transaction_aborts_on_exception_without_corrupting_disk(): void
    {
        $path = "{$this->tempDir}/atomic.json";
        $manifest = Manifest::open($path, files: $this->files);
        $manifest->write(['safe' => true, 'balance' => 100]);

        try {
            $manifest->transaction(function (ManifestDocument $doc): void {
                $doc->set('balance', 0);
                $doc->set('corrupted', true);

                throw new RuntimeException('Unexpected loop failure');
            });
            $this->fail('Transaction should have rethrown exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Unexpected loop failure', $e->getMessage());
        }

        // Verify disk remains completely unchanged
        $onDisk = $manifest->read();
        $this->assertTrue($onDisk->get('safe'));
        $this->assertSame(100, $onDisk->get('balance'));
        $this->assertFalse($onDisk->has('corrupted'));
    }
}
