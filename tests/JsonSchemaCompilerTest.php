<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Contracts\HasJsonSchema;
use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use AlexKassel\ManifestEngine\Schemas\JsonSchemaCompiler;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class JsonSchemaCompilerTest extends TestCase
{
    public function test_it_compiles_basic_laravel_rules_into_draft_07_json_schema(): void
    {
        $compiler = new JsonSchemaCompiler;

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'age' => ['nullable', 'integer', 'min:18'],
            'email' => ['sometimes', 'string', 'email'],
            'status' => ['required', 'in:active,pending,archived'],
            'tags' => ['present', 'array'],
            'tags.*' => ['string'],
        ];

        $descriptions = [
            'name' => 'The user full name.',
            'age' => 'User age in years.',
        ];

        $schema = $compiler->compile(
            schemaOrRules: $rules,
            descriptions: $descriptions,
            title: 'UserSchema',
            description: 'User validation schema'
        );

        $this->assertSame(JsonSchemaCompiler::SCHEMA_DRAFT_07, $schema['$schema']);
        $this->assertSame('User validation schema', $schema['description']);
        $this->assertSame('object', $schema['type']);
        $this->assertTrue($schema['additionalProperties']);

        // Check required
        $this->assertContains('name', $schema['required']);
        $this->assertContains('status', $schema['required']);
        $this->assertContains('tags', $schema['required']);
        $this->assertNotContains('age', $schema['required'] ?? []);

        // Check properties
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame(2, $schema['properties']['name']['minLength']);
        $this->assertSame(50, $schema['properties']['name']['maxLength']);
        $this->assertSame('The user full name.', $schema['properties']['name']['description']);

        $this->assertSame(['integer', 'null'], $schema['properties']['age']['type']);
        $this->assertSame(18, $schema['properties']['age']['minimum']);
        $this->assertSame('User age in years.', $schema['properties']['age']['description']);

        $this->assertSame('string', $schema['properties']['email']['type']);
        $this->assertSame('email', $schema['properties']['email']['format']);

        $this->assertSame(['active', 'pending', 'archived'], $schema['properties']['status']['enum']);

        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('string', $schema['properties']['tags']['items']['type']);
    }

    public function test_it_integrates_with_custom_rule_implementing_has_json_schema(): void
    {
        $customRule = new class implements HasJsonSchema, ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                // runtime check
            }

            public function toSchema(): array
            {
                return [
                    'oneOf' => [
                        ['type' => 'string', 'description' => 'Plain package slug'],
                        ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                    ],
                ];
            }
        };

        $rules = [
            'packages' => ['present', 'array'],
            'packages.*' => [$customRule],
        ];

        $compiler = new JsonSchemaCompiler;
        $schema = $compiler->compile($rules);

        $this->assertArrayHasKey('oneOf', $schema['properties']['packages']['items']);
        $this->assertCount(2, $schema['properties']['packages']['items']['oneOf']);
    }

    public function test_base_schema_automatically_compiles_json_schema(): void
    {
        $schemaInstance = new class extends BaseSchema
        {
            public function defaults(): array
            {
                return ['version' => 1];
            }

            public function rules(): array
            {
                return [
                    'version' => ['required', 'integer'],
                    'notes' => ['sometimes', 'nullable', 'string'],
                ];
            }

            public function descriptions(): array
            {
                return [
                    'version' => 'Schema version number.',
                ];
            }

            public function title(): ?string
            {
                return 'AutoSchema';
            }
        };

        $jsonSchema = $schemaInstance->jsonSchema();

        $this->assertNotNull($jsonSchema);
        $this->assertSame('AutoSchema', $jsonSchema['title']);
        $this->assertSame('integer', $jsonSchema['properties']['version']['type']);
        $this->assertSame('Schema version number.', $jsonSchema['properties']['version']['description']);
        $this->assertSame(['string', 'null'], $jsonSchema['properties']['notes']['type']);
    }
}
