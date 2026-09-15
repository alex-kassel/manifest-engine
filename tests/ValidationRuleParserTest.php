<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Tests;

use AlexKassel\ManifestEngine\Schemas\JsonSchemaCompiler;
use Illuminate\Validation\Rule;

enum DemoRoleEnum: string
{
    case Admin = 'admin';
    case Member = 'member';
}

class ValidationRuleParserTest extends TestCase
{
    public function test_it_compiles_rules_with_regex_and_objects_safely(): void
    {
        $compiler = new JsonSchemaCompiler;

        $rules = [
            'identifier' => ['required', 'string', 'regex:/^[a-z|0-9]+$/'],
            'environment' => ['required', Rule::in(['local', 'staging', 'production'])],
            'role' => ['required', Rule::enum(DemoRoleEnum::class)],
            'website' => ['nullable', 'url'],
            'server_ip' => ['nullable', 'ip'],
            'created_at' => ['nullable', 'date'],
        ];

        $schema = $compiler->compile($rules, 'Advanced Schema');

        $this->assertSame('Advanced Schema', $schema['title']);
        $this->assertContains('identifier', $schema['required']);
        $this->assertContains('environment', $schema['required']);
        $this->assertContains('role', $schema['required']);

        $this->assertSame('string', $schema['properties']['identifier']['type']);
        $this->assertSame(['local', 'staging', 'production'], $schema['properties']['environment']['enum']);
        $this->assertSame(['admin', 'member'], $schema['properties']['role']['enum']);

        $this->assertSame('uri', $schema['properties']['website']['format']);
        $this->assertSame('ipv4', $schema['properties']['server_ip']['format']);
        $this->assertSame('date-time', $schema['properties']['created_at']['format']);
    }
}
