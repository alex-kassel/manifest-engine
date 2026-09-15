<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Facades;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Manifest as ManifestDocument;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ManifestDocument open(string $path, ?ManifestSchema $schema = null, ?Filesystem $files = null)
 *
 * @see ManifestDocument
 */
class Manifest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'manifest.engine';
    }
}
