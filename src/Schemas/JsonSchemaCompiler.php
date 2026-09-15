<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Schemas;

class JsonSchemaCompiler
{
    public const SCHEMA_DRAFT_07 = 'http://json-schema.org/draft-07/schema#';

    public const TYPE_OBJECT = 'object';

    public const TYPE_ARRAY = 'array';

    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const RULE_REQUIRED = 'required';

    public const RULE_STRING = 'string';

    public const RULE_INTEGER = 'integer';

    public const RULE_NUMERIC = 'numeric';

    public const RULE_BOOLEAN = 'boolean';

    public const RULE_ARRAY = 'array';

    public const RULE_IN_PREFIX = 'in:';

    public const RULE_MIN_PREFIX = 'min:';

    public const RULE_MAX_PREFIX = 'max:';

    public const RULE_DELIMITER = '|';

    public const VALUE_DELIMITER = ',';

    public const WILDCARD_SEGMENT = '*';

    public const DEFAULT_EMPTY_RULES = [];

    /**
     * Compile Laravel validation rules into a Draft-07 JSON Schema array.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function compile(array $rules, ?string $title = null): array
    {
        $schema = [
            '$schema' => self::SCHEMA_DRAFT_07,
            'type' => self::TYPE_OBJECT,
            'properties' => [],
            'required' => [],
        ];

        if ($title !== null && trim($title) !== '') {
            $schema['title'] = trim($title);
        }

        foreach ($rules as $field => $fieldRules) {
            $parsedRules = $this->normalizeRules($fieldRules);
            $this->applyFieldRule($schema, (string) $field, $parsedRules);
        }

        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Normalize string or array rules into a flat array of rule strings.
     *
     * @return array<int, string>
     */
    protected function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode(self::RULE_DELIMITER, $rules);
        }

        if (is_array($rules)) {
            $normalized = [];
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $normalized[] = $rule;
                }
            }

            return $normalized;
        }

        return self::DEFAULT_EMPTY_RULES;
    }

    /**
     * Apply a single field's rules to the schema tree.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $rules
     */
    protected function applyFieldRule(array &$schema, string $field, array $rules): void
    {
        $segments = explode('.', $field);
        $this->insertRuleIntoNode($schema, $segments, $rules);
    }

    /**
     * Recursively traverse schema nodes to place field rules.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $segments
     * @param  array<int, string>  $rules
     */
    protected function insertRuleIntoNode(array &$node, array $segments, array $rules): void
    {
        $currentSegment = array_shift($segments);
        if ($currentSegment === null) {
            return;
        }

        if ($currentSegment === self::WILDCARD_SEGMENT) {
            if (! isset($node['items']) || ! is_array($node['items'])) {
                $node['items'] = [];
            }

            if (empty($segments)) {
                $this->populatePropertySchema($node['items'], $rules);
            } else {
                $this->insertRuleIntoNode($node['items'], $segments, $rules);
            }

            return;
        }

        if (! isset($node['properties']) || ! is_array($node['properties'])) {
            $node['properties'] = [];
        }

        if (! isset($node['properties'][$currentSegment]) || ! is_array($node['properties'][$currentSegment])) {
            $node['properties'][$currentSegment] = [];
        }

        if (empty($segments)) {
            $this->populatePropertySchema($node['properties'][$currentSegment], $rules);

            if (in_array(self::RULE_REQUIRED, $rules, true)) {
                if (! isset($node['required']) || ! is_array($node['required'])) {
                    $node['required'] = [];
                }
                if (! in_array($currentSegment, $node['required'], true)) {
                    $node['required'][] = $currentSegment;
                }
            }
        } else {
            if (! isset($node['properties'][$currentSegment]['type'])) {
                $nextSegment = $segments[0] ?? null;
                $node['properties'][$currentSegment]['type'] = $nextSegment === self::WILDCARD_SEGMENT
                    ? self::TYPE_ARRAY
                    : self::TYPE_OBJECT;
            }

            $this->insertRuleIntoNode($node['properties'][$currentSegment], $segments, $rules);
        }
    }

    /**
     * Populate property schema type, constraints, and enums from rules.
     *
     * @param  array<string, mixed>  $prop
     * @param  array<int, string>  $rules
     */
    protected function populatePropertySchema(array &$prop, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule === self::RULE_STRING) {
                $prop['type'] = self::TYPE_STRING;
            } elseif ($rule === self::RULE_INTEGER) {
                $prop['type'] = self::TYPE_INTEGER;
            } elseif ($rule === self::RULE_NUMERIC) {
                $prop['type'] = self::TYPE_NUMBER;
            } elseif ($rule === self::RULE_BOOLEAN) {
                $prop['type'] = self::TYPE_BOOLEAN;
            } elseif ($rule === self::RULE_ARRAY) {
                $prop['type'] = self::TYPE_ARRAY;
            } elseif (str_starts_with($rule, self::RULE_IN_PREFIX)) {
                $values = explode(self::VALUE_DELIMITER, substr($rule, strlen(self::RULE_IN_PREFIX)));
                $prop['enum'] = array_values(array_map('trim', $values));
            } elseif (str_starts_with($rule, self::RULE_MIN_PREFIX)) {
                $val = substr($rule, strlen(self::RULE_MIN_PREFIX));
                if (is_numeric($val)) {
                    $num = str_contains($val, '.') ? (float) $val : (int) $val;
                    if (($prop['type'] ?? null) === self::TYPE_STRING) {
                        $prop['minLength'] = (int) $num;
                    } else {
                        $prop['minimum'] = $num;
                    }
                }
            } elseif (str_starts_with($rule, self::RULE_MAX_PREFIX)) {
                $val = substr($rule, strlen(self::RULE_MAX_PREFIX));
                if (is_numeric($val)) {
                    $num = str_contains($val, '.') ? (float) $val : (int) $val;
                    if (($prop['type'] ?? null) === self::TYPE_STRING) {
                        $prop['maxLength'] = (int) $num;
                    } else {
                        $prop['maximum'] = $num;
                    }
                }
            }
        }
    }
}
