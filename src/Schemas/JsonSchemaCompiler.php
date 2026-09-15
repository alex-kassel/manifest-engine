<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Schemas;

use BackedEnum;
use Illuminate\Validation\Rules\Enum;
use ReflectionProperty;
use Stringable;

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

    public const RULE_NULLABLE = 'nullable';

    public const RULE_STRING = 'string';

    public const RULE_INTEGER = 'integer';

    public const RULE_NUMERIC = 'numeric';

    public const RULE_BOOLEAN = 'boolean';

    public const RULE_ARRAY = 'array';

    public const RULE_EMAIL = 'email';

    public const RULE_UUID = 'uuid';

    public const RULE_URL = 'url';

    public const RULE_IP = 'ip';

    public const RULE_IPV4 = 'ipv4';

    public const RULE_IPV6 = 'ipv6';

    public const RULE_DATE = 'date';

    public const RULE_JSON = 'json';

    public const RULE_IN_PREFIX = 'in:';

    public const RULE_MIN_PREFIX = 'min:';

    public const RULE_MAX_PREFIX = 'max:';

    public const RULE_DELIMITER = '|';

    public const VALUE_DELIMITER = ',';

    public const WILDCARD_SEGMENT = '*';

    public const FORMAT_EMAIL = 'email';

    public const FORMAT_UUID = 'uuid';

    public const FORMAT_URI = 'uri';

    public const FORMAT_IPV4 = 'ipv4';

    public const FORMAT_IPV6 = 'ipv6';

    public const FORMAT_DATE_TIME = 'date-time';

    /**
     * @var array<int, string>
     */
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
     * Normalize string, object, or array rules into a flat array of rule strings or rule objects.
     *
     * @return array<int, mixed>
     */
    protected function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode(self::RULE_DELIMITER, $rules);
        }

        if ($rules instanceof Enum) {
            return [$rules];
        }

        if ($rules instanceof Stringable || (is_object($rules) && method_exists($rules, '__toString'))) {
            return explode(self::RULE_DELIMITER, (string) $rules);
        }

        if (is_array($rules)) {
            $normalized = [];
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $normalized[] = $rule;
                } elseif ($rule instanceof Enum) {
                    $normalized[] = $rule;
                } elseif ($rule instanceof Stringable || (is_object($rule) && method_exists($rule, '__toString'))) {
                    $normalized[] = (string) $rule;
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
     * @param  array<int, mixed>  $rules
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
     * @param  array<int, mixed>  $rules
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
     * @param  array<int, mixed>  $rules
     */
    protected function populatePropertySchema(array &$prop, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule instanceof Enum) {
                $refProp = new ReflectionProperty($rule, 'type');
                $enumClass = (string) $refProp->getValue($rule);
                if (enum_exists($enumClass)) {
                    $cases = array_map(static fn ($case) => $case instanceof BackedEnum ? $case->value : $case->name, $enumClass::cases());
                    $prop['enum'] = $cases;
                    $prop['type'] = is_int($cases[0] ?? null) ? self::TYPE_INTEGER : self::TYPE_STRING;
                }

                continue;
            }

            if (! is_string($rule)) {
                continue;
            }

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
            } elseif ($rule === self::RULE_NULLABLE) {
                $prop['nullable'] = true;
            } elseif ($rule === self::RULE_EMAIL) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_EMAIL;
            } elseif ($rule === self::RULE_UUID) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_UUID;
            } elseif ($rule === self::RULE_URL) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_URI;
            } elseif ($rule === self::RULE_IP || $rule === self::RULE_IPV4) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_IPV4;
            } elseif ($rule === self::RULE_IPV6) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_IPV6;
            } elseif ($rule === self::RULE_DATE) {
                $prop['type'] ??= self::TYPE_STRING;
                $prop['format'] = self::FORMAT_DATE_TIME;
            } elseif ($rule === self::RULE_JSON) {
                $prop['type'] ??= self::TYPE_STRING;
            } elseif (str_starts_with($rule, self::RULE_IN_PREFIX)) {
                $values = explode(self::VALUE_DELIMITER, substr($rule, strlen(self::RULE_IN_PREFIX)));
                $prop['enum'] = array_values(array_map(static fn (string $val): string => trim(trim($val), "\"'"), $values));
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
