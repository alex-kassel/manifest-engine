<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\ManifestEngineServiceProvider;

if (class_exists(\Orchestra\Testbench\TestCase::class)) {
    class_alias(\Orchestra\Testbench\TestCase::class, __NAMESPACE__.'\BaseTestCase');
} elseif (class_exists(\Tests\TestCase::class)) {
    class_alias(\Tests\TestCase::class, __NAMESPACE__.'\BaseTestCase');
} else {
    class_alias(\PHPUnit\Framework\TestCase::class, __NAMESPACE__.'\BaseTestCase');
}

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ManifestEngineServiceProvider::class,
        ];
    }
}
