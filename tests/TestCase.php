<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\ManifestEngineServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ManifestEngineServiceProvider::class,
        ];
    }
}
