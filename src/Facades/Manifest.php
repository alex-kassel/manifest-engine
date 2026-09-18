<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Facades;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Manifest as ManifestStore;
use AlexKassel\ManifestEngine\ManifestManager;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @property-read ManifestRegistry $registry
 *
 * @method static ManifestManager register(ManifestDefinition $definition)
 * @method static ManifestStore open(string $target, ?ManifestSchema $schema = null)
 *
 * @see ManifestManager
 */
class Manifest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ManifestManager::class;
    }
}
