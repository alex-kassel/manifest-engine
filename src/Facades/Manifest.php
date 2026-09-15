<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Facades;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Manifest as ManifestDocument;
use AlexKassel\ManifestEngine\ManifestManager;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ManifestDocument open(string $path, ?ManifestSchema $schema = null)
 * @method static ManifestRegistry register(string $name, string $filename, ManifestSchema|string $schema, ?string $runnerPath = null, ?string $description = null)
 * @method static ManifestRegistry registry()
 * @method static Filesystem files()
 *
 * @see ManifestManager
 */
class Manifest extends Facade
{
    public const FACADE_ACCESSOR = 'manifest.engine';

    protected static function getFacadeAccessor(): string
    {
        return self::FACADE_ACCESSOR;
    }
}
