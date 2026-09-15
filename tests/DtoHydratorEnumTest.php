<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Hydration\DtoHydrator;

enum TestStatusEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}

class AuthorDto
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

class PostDto
{
    public function __construct(
        public string $title,
        public TestStatusEnum $status,
        public AuthorDto $author,
    ) {}
}

class SpatieLikeDto
{
    public function __construct(
        public string $slug,
        public int $hits,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            slug: (string) ($data['slug'] ?? ''),
            hits: (int) ($data['hits'] ?? 0),
        );
    }
}

class DtoHydratorEnumTest extends TestCase
{
    public function test_it_hydrates_backed_enums_and_nested_dtos(): void
    {
        $hydrator = new DtoHydrator;

        $raw = [
            'title' => 'My First Post',
            'status' => 'published',
            'author' => [
                'name' => 'Alex',
                'email' => 'alex@example.com',
            ],
        ];

        /** @var PostDto $dto */
        $dto = $hydrator->hydrate(PostDto::class, $raw);

        $this->assertInstanceOf(PostDto::class, $dto);
        $this->assertSame('My First Post', $dto->title);
        $this->assertSame(TestStatusEnum::Published, $dto->status);
        $this->assertInstanceOf(AuthorDto::class, $dto->author);
        $this->assertSame('Alex', $dto->author->name);
        $this->assertSame('alex@example.com', $dto->author->email);
    }

    public function test_it_serializes_backed_enums_and_nested_objects(): void
    {
        $hydrator = new DtoHydrator;

        $dto = new PostDto(
            title: 'Serializable Post',
            status: TestStatusEnum::Archived,
            author: new AuthorDto('Jane', 'jane@example.com')
        );

        $serialized = $hydrator->serialize($dto);

        $this->assertSame([
            'title' => 'Serializable Post',
            'status' => 'archived',
            'author' => [
                'name' => 'Jane',
                'email' => 'jane@example.com',
            ],
        ], $serialized);
    }

    public function test_it_supports_spatie_data_from_convention(): void
    {
        $hydrator = new DtoHydrator;

        $raw = ['slug' => 'hello-world', 'hits' => 150];

        /** @var SpatieLikeDto $dto */
        $dto = $hydrator->hydrate(SpatieLikeDto::class, $raw);

        $this->assertInstanceOf(SpatieLikeDto::class, $dto);
        $this->assertSame('hello-world', $dto->slug);
        $this->assertSame(150, $dto->hits);
    }
}
