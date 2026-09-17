<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Schemas;

use AlexKassel\ManifestEngine\Contracts\HasJsonSchema;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\Validation\ValidationRuleParser;

class JsonSchemaCompiler
{
    public const SCHEMA_DRAFT_07 = 'http://json-schema.org/draft-07/schema#';

    public const TYPE_OBJECT = 'object';

    public const TYPE_ARRAY = 'array';

    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const RULE_REQUIRED = 'Required';

    public const RULE_PRESENT = 'Present';

    public const RULE_NULLABLE = 'Nullable';

    public const RULE_STRING = 'String';

    public const RULE_INTEGER = 'Integer';

    public const RULE_NUMERIC = 'Numeric';

    public const RULE_BOOLEAN = 'Boolean';

    public const RULE_ARRAY = 'Array';

    public const RULE_IN = 'In';

    public const RULE_MIN = 'Min';

    public const RULE_MAX = 'Max';

    public const RULE_EMAIL = 'Email';

    public const RULE_UUID = 'Uuid';

    public const RULE_URL = 'Url';

    public const FORMAT_EMAIL = 'email';

    public const FORMAT_UUID = 'uuid';

    public const FORMAT_URI = 'uri';

    public const WILDCARD = '*';

    public const DOT = '.';

    /**
     * Compile a ManifestSchema or rules array into a Draft-07 JSON Schema.
     *
     * @param  ManifestSchema|array<string, mixed>  $schemaOrRules
     * @param  array<string, string>  $descriptions
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    public function compile(
        ManifestSchema|array $schemaOrRules,
        array $descriptions = [],
        array $types = [],
        ?string $title = null,
        ?string $description = null,
    ): array {
        if ($schemaOrRules instanceof ManifestSchema) {
            $rules = $schemaOrRules->rules();
            $descriptions = array_merge($schemaOrRules->descriptions(), $descriptions);
            $types = array_merge($schemaOrRules->types(), $types);
            $title ??= method_exists($schemaOrRules, 'title') ? $schemaOrRules->title() : class_basename($schemaOrRules);
            $description ??= method_exists($schemaOrRules, 'description') ? $schemaOrRules->description() : null;
        } else {
            $rules = $schemaOrRules;
        }

        $root = [
            '$schema' => self::SCHEMA_DRAFT_07,
            'title' => $title ?? 'Manifest',
            'type' => self::TYPE_OBJECT,
            'properties' => [],
            'required' => [],
            'additionalProperties' => true,
        ];

        if ($description !== null && trim($description) !== '') {
            $root['description'] = trim($description);
        }

        foreach ($rules as $attribute => $attributeRules) {
            $this->applyAttributeRule(
                $root,
                (string) $attribute,
                (array) $attributeRules,
                $descriptions,
                $types
            );
        }

        if (empty($root['required'])) {
            unset($root['required']);
        }

        return $root;
    }

    /**
     * Apply rules for a specific dot-notation attribute.
     *
     * @param  array<string, mixed>  $root
     * @param  array<int, mixed>  $rules
     * @param  array<string, string>  $descriptions
     * @param  array<string, string>  $types
     */
    protected function applyAttributeRule(
        array &$root,
        string $attribute,
        array $rules,
        array $descriptions,
        array $types,
    ): void {
        $segments = explode(self::DOT, $attribute);
        $target = &$root;
        $pathAccumulator = [];

        foreach ($segments as $index => $segment) {
            $isLast = $index === count($segments) - 1;
            $pathAccumulator[] = $segment;
            $currentPath = implode(self::DOT, $pathAccumulator);

            if ($segment === self::WILDCARD) {
                if (($target['type'] ?? null) === self::TYPE_OBJECT || isset($target['additionalProperties'])) {
                    if (! isset($target['additionalProperties']) || ! is_array($target['additionalProperties'])) {
                        $target['additionalProperties'] = [
                            'type' => self::TYPE_OBJECT,
                            'properties' => [],
                            'additionalProperties' => true,
                        ];
                    }
                    $target = &$target['additionalProperties'];
                } else {
                    if (! isset($target['items']) || ! is_array($target['items'])) {
                        $target['items'] = [];
                    }
                    $target = &$target['items'];
                }

                if ($isLast) {
                    $this->populateNode(
                        $target,
                        $rules,
                        $descriptions[$currentPath] ?? null,
                        $types[$currentPath] ?? null
                    );
                }

                continue;
            }

            if (! isset($target['properties'][$segment])) {
                $target['properties'][$segment] = [];
            }

            $target = &$target['properties'][$segment];

            if ($isLast) {
                $this->populateNode(
                    $target,
                    $rules,
                    $descriptions[$currentPath] ?? null,
                    $types[$currentPath] ?? null
                );

                if ($this->isRequiredRule($rules)) {
                    $parent = &$this->resolveParentNode($root, array_slice($segments, 0, $index));
                    if (! isset($parent['required']) || ! is_array($parent['required'])) {
                        $parent['required'] = [];
                    }
                    if (! in_array($segment, $parent['required'], true)) {
                        $parent['required'][] = $segment;
                    }
                }
            }
        }
    }

    /**
     * Resolve the parent node reference for adding 'required' constraint.
     *
     * @param  array<string, mixed>  $root
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    protected function &resolveParentNode(array &$root, array $segments): array
    {
        $target = &$root;
        foreach ($segments as $segment) {
            if ($segment === self::WILDCARD) {
                if (($target['type'] ?? null) === self::TYPE_OBJECT || isset($target['additionalProperties'])) {
                    $target = &$target['additionalProperties'];
                } else {
                    $target = &$target['items'];
                }
            } else {
                $target = &$target['properties'][$segment];
            }
        }

        return $target;
    }

    /**
     * Check if given rules array contains 'required' or 'present'.
     *
     * @param  array<int, mixed>  $rules
     */
    protected function isRequiredRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                [$name] = ValidationRuleParser::parse($rule);
                if (in_array($name, [self::RULE_REQUIRED, self::RULE_PRESENT], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Populate a schema node with types, formats, constraints, and descriptions.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $rules
     */
    protected function populateNode(
        array &$node,
        array $rules,
        ?string $description,
        ?string $explicitType,
    ): void {
        if ($description !== null) {
            $node['description'] = $description;
        }

        $isNullable = false;
        $inferredType = $explicitType;
        $min = null;
        $max = null;

        foreach ($rules as $rule) {
            if ($rule instanceof HasJsonSchema) {
                $customSchema = $rule->toSchema();
                $node = array_merge($node, $customSchema);

                return;
            }

            if (! is_string($rule)) {
                continue;
            }

            [$name, $params] = ValidationRuleParser::parse($rule);

            match ($name) {
                self::RULE_NULLABLE => $isNullable = true,
                self::RULE_STRING => $inferredType ??= self::TYPE_STRING,
                self::RULE_INTEGER => $inferredType ??= self::TYPE_INTEGER,
                self::RULE_NUMERIC => $inferredType ??= self::TYPE_NUMBER,
                self::RULE_BOOLEAN => $inferredType ??= self::TYPE_BOOLEAN,
                self::RULE_ARRAY => $inferredType ??= self::TYPE_ARRAY,
                self::RULE_IN => $node['enum'] = $params,
                self::RULE_MIN => $min = $params[0] ?? null,
                self::RULE_MAX => $max = $params[0] ?? null,
                self::RULE_EMAIL => $node['format'] = self::FORMAT_EMAIL,
                self::RULE_UUID => $node['format'] = self::FORMAT_UUID,
                self::RULE_URL => $node['format'] = self::FORMAT_URI,
                default => null,
            };
        }

        if ($inferredType !== null) {
            $node['type'] = $isNullable ? [$inferredType, 'null'] : $inferredType;
        }

        if ($min !== null) {
            $this->applyMinConstraint($node, $inferredType, $min);
        }

        if ($max !== null) {
            $this->applyMaxConstraint($node, $inferredType, $max);
        }
    }

    /**
     * Apply minimum constraint based on node type.
     *
     * @param  array<string, mixed>  $node
     */
    protected function applyMinConstraint(array &$node, ?string $inferredType, ?string $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $num = (int) $value;
        if ($inferredType === self::TYPE_STRING) {
            $node['minLength'] = $num;
        } elseif ($inferredType === self::TYPE_ARRAY) {
            $node['minItems'] = $num;
        } else {
            $node['minimum'] = $num;
        }
    }

    /**
     * Apply maximum constraint based on node type.
     *
     * @param  array<string, mixed>  $node
     */
    protected function applyMaxConstraint(array &$node, ?string $inferredType, ?string $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $num = (int) $value;
        if ($inferredType === self::TYPE_STRING) {
            $node['maxLength'] = $num;
        } elseif ($inferredType === self::TYPE_ARRAY) {
            $node['maxItems'] = $num;
        } else {
            $node['maximum'] = $num;
        }
    }
}
