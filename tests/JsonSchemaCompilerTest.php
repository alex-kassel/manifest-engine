<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Schemas\JsonSchemaCompiler;
use Illuminate\Validation\Rule;
use Stringable;

class JsonSchemaCompilerTest extends TestCase
{
    protected JsonSchemaCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new JsonSchemaCompiler;
    }

    public function test_it_compiles_extended_types_and_formats(): void
    {
        $schema = $this->compiler->compile([
            'email' => 'required|string|email',
            'token' => 'required|string|uuid',
            'description' => 'nullable|string',
        ]);

        $this->assertSame('string', $schema['properties']['email']['type']);
        $this->assertSame('email', $schema['properties']['email']['format']);

        $this->assertSame('string', $schema['properties']['token']['type']);
        $this->assertSame('uuid', $schema['properties']['token']['format']);

        $this->assertSame('string', $schema['properties']['description']['type']);
        $this->assertTrue($schema['properties']['description']['nullable']);
    }

    public function test_it_handles_stringable_and_object_rules(): void
    {
        $stringableRule = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'in:apple,banana';
            }
        };

        $schema = $this->compiler->compile([
            'fruit' => ['required', $stringableRule],
            'status' => [Rule::in(['active', 'pending'])],
        ]);

        $this->assertSame(['apple', 'banana'], $schema['properties']['fruit']['enum']);
        $this->assertSame(['active', 'pending'], $schema['properties']['status']['enum']);
    }
}
