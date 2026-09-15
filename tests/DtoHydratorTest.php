<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Hydration\DtoHydrator;

class SampleSimpleDto
{
    public function __construct(
        public string $title,
        public int $count = 10,
    ) {}
}

class SamplePropertyDto
{
    public string $name;

    public bool $enabled;
}

class DtoHydratorTest extends TestCase
{
    protected DtoHydrator $hydrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hydrator = new DtoHydrator;
    }

    public function test_it_hydrates_constructor_promoted_dto(): void
    {
        $dto = $this->hydrator->hydrate(SampleSimpleDto::class, [
            'title' => 'Engine',
            'count' => 42,
        ]);

        $this->assertInstanceOf(SampleSimpleDto::class, $dto);
        $this->assertSame('Engine', $dto->title);
        $this->assertSame(42, $dto->count);
    }

    public function test_it_hydrates_dto_with_default_values(): void
    {
        $dto = $this->hydrator->hydrate(SampleSimpleDto::class, [
            'title' => 'Engine',
        ]);

        $this->assertSame('Engine', $dto->title);
        $this->assertSame(10, $dto->count);
    }

    public function test_it_hydrates_property_based_dto(): void
    {
        $dto = $this->hydrator->hydrate(SamplePropertyDto::class, [
            'name' => 'Alice',
            'enabled' => true,
        ]);

        $this->assertInstanceOf(SamplePropertyDto::class, $dto);
        $this->assertSame('Alice', $dto->name);
        $this->assertTrue($dto->enabled);
    }

    public function test_it_serializes_dto(): void
    {
        $dto = new SampleSimpleDto('Test', 99);
        $data = $this->hydrator->serialize($dto);

        $this->assertSame(['title' => 'Test', 'count' => 99], $data);
    }
}
