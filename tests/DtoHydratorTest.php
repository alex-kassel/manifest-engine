<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
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

    public function test_it_hydrates_nested_typed_collections(): void
    {
        $data = [
            'orderId' => 'ORD-101',
            'items' => [
                ['sku' => 'ITEM-A', 'qty' => 2],
                ['sku' => 'ITEM-B', 'qty' => 5],
            ],
        ];

        $order = $this->hydrator->hydrate(SampleOrderDto::class, $data);

        $this->assertInstanceOf(SampleOrderDto::class, $order);
        $this->assertSame('ORD-101', $order->orderId);
        $this->assertCount(2, $order->items);
        $this->assertInstanceOf(SampleItemDto::class, $order->items[0]);
        $this->assertSame('ITEM-A', $order->items[0]->sku);
        $this->assertSame(2, $order->items[0]->qty);
        $this->assertInstanceOf(SampleItemDto::class, $order->items[1]);
        $this->assertSame('ITEM-B', $order->items[1]->sku);

        // Verify serialization back to primitive array
        $serialized = $this->hydrator->serialize($order);
        $this->assertSame($data, $serialized);
    }
}

class SampleItemDto
{
    public function __construct(
        public string $sku,
        public int $qty,
    ) {}
}

class SampleOrderDto implements ManifestDto
{
    public function __construct(
        public string $orderId,
        public array $items = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            orderId: (string) ($data['orderId'] ?? ''),
            items: array_map(fn ($item) => $item instanceof SampleItemDto ? $item : new SampleItemDto($item['sku'], $item['qty']), $data['items'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'items' => array_map(fn (SampleItemDto $item) => ['sku' => $item->sku, 'qty' => $item->qty], $this->items),
        ];
    }
}
