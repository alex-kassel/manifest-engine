<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;

class ManifestRegistryTest extends TestCase
{
    public function test_manifest_registry_registers_and_retrieves_definitions(): void
    {
        $registry = new ManifestRegistry;

        $schema = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return [];
            }
        };

        $def1 = new ManifestDefinition('app', 'app.json', $schema, 'App manifest');
        $def2 = new ManifestDefinition('db', 'db.json', $schema);

        $this->assertFalse($registry->has('app'));
        $this->assertNull($registry->get('app'));
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->names());

        $registry->register($def1)->register($def2);

        $this->assertTrue($registry->has('app'));
        $this->assertTrue($registry->has('db'));
        $this->assertFalse($registry->has('unknown'));

        $this->assertSame($def1, $registry->get('app'));
        $this->assertSame($def2, $registry->get('db'));
        $this->assertSame(['app' => $def1, 'db' => $def2], $registry->all());
        $this->assertSame(['app', 'db'], $registry->names());

        $registry->forget('app');
        $this->assertFalse($registry->has('app'));
        $this->assertTrue($registry->has('db'));
        $this->assertSame(['db'], $registry->names());

        $registry->clear();
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->names());
    }
}
