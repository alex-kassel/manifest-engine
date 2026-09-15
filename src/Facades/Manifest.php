<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Facades;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Manifest as ManifestDocument;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ManifestDocument open(string $path, ?ManifestSchema $schema = null, ?Filesystem $files = null)
 * @method static ManifestRegistry register(string $name, string $filename, ManifestSchema|string $schema, ?string $runnerPath = null, ?string $description = null)
 * @method static ManifestRegistry registry()
 *
 * @see ManifestDocument
 * @see ManifestRegistry
 */
class Manifest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'manifest.engine';
    }
}
