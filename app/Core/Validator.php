<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeZone;

/**
 * Server-side validation. Rules are pipe-separated strings:
 *   'email' => 'required|email|max:191|unique:users,email,5'
 *
 * Supported: required, nullable, string, email, integer, boolean, array,
 *            min:n, max:n, in:a,b, confirmed, unique:table,column[,ignoreId],
 *            exists:table,column, timezone, regex:/pattern/
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $validated = [];

    /** @param array<string, mixed> $data @param array<string, string> $rules @param array<string, string> $labels */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
        private readonly ?Database $db = null,
    ) {
        $this->run();
    }

    /** @param array<string, mixed> $data @param array<string, string> $rules @param array<string, string> $labels */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        $db = null;
        foreach ($rules as $r) {
            if (str_contains($r, 'unique:') || str_contains($r, 'exists:')) {
                $db = App::db();
                break;
            }
        }
        return new self($data, $rules, $labels, $db);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $empty = $value === null || $value === '' || $value === [];

            if (in_array('required', $rules, true) && $empty) {
                $this->errors[$field][] = "{$label} is required.";
                continue;
            }
            if ($empty) {
                if (in_array('array', $rules, true)) {
                    $this->validated[$field] = [];
                } elseif (in_array('boolean', $rules, true)) {
                    $this->validated[$field] = false;
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }

            $this->currentField = $field;
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $arg, $value, $field, $label);
                if ($error !== null) {
                    $this->errors[$field][] = $error;
                    break;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $this->cast($rules, $value);
            }
        }
    }

    private function cast(array $rules, mixed $value): mixed
    {
        return match (true) {
            in_array('integer', $rules, true) => (int) $value,
            in_array('boolean', $rules, true) => in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true),
            in_array('array', $rules, true) => array_values((array) $value),
            default => $value,
        };
    }

    private function check(string $rule, ?string $arg, mixed $value, string $field, string $label): ?string
    {
        $isArray = is_array($value);
        return match ($rule) {
            'required', 'nullable' => null,
            'string' => is_string($value) ? null : "{$label} must be text.",
            'array' => $isArray ? null : "{$label} is invalid.",
            'email' => !$isArray && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "{$label} must be a valid email address.",
            'integer' => !$isArray && filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "{$label} must be a whole number.",
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off', 'true', 'false', 'yes', 'no'], true) ? null : "{$label} is invalid.",
            'min' => $this->size($value) >= (float) $arg ? null : ($this->numeric($value)
                ? "{$label} must be at least {$arg}." : "{$label} must be at least {$arg} characters."),
            'max' => $this->size($value) <= (float) $arg ? null : ($this->numeric($value)
                ? "{$label} may not be greater than {$arg}." : "{$label} may not exceed {$arg} characters."),
            'in' => !$isArray && in_array((string) $value, explode(',', (string) $arg), true) ? null : "{$label} has an invalid value.",
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value ? null : "{$label} confirmation does not match.",
            'timezone' => !$isArray && in_array($value, DateTimeZone::listIdentifiers(), true) ? null : "{$label} must be a valid timezone.",
            'regex' => !$isArray && preg_match((string) $arg, (string) $value) === 1 ? null : "{$label} format is invalid.",
            'unique' => $this->unique((string) $arg, $value) ? null : "{$label} has already been taken.",
            'exists' => $this->exists((string) $arg, $value) ? null : "{$label} contains an invalid selection.",
            default => throw new \InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };
    }

    private ?string $currentField = null;

    private function isIntegerField(): bool
    {
        return $this->currentField !== null
            && in_array('integer', explode('|', $this->rules[$this->currentField] ?? ''), true);
    }

    private function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || $this->isIntegerField();
    }

    /** Arrays: item count. Integer fields: numeric value. Everything else: string length. */
    private function size(mixed $value): float
    {
        return match (true) {
            is_array($value) => (float) count($value),
            is_int($value), is_float($value) => (float) $value,
            $this->isIntegerField() && is_numeric($value) => (float) $value,
            default => (float) mb_strlen((string) $value),
        };
    }

    private function unique(string $arg, mixed $value): bool
    {
        [$table, $column, $ignore] = array_pad(explode(',', $arg), 3, null);
        $db = $this->db ?? App::db();
        $sql = 'SELECT COUNT(*) FROM ' . $db->identifier((string) $table) . ' WHERE ' . $db->identifier((string) $column) . ' = ?';
        $params = [$value];
        if ($ignore !== null && $ignore !== '') {
            $sql .= ' AND id <> ?';
            $params[] = (int) $ignore;
        }
        return (int) $db->scalar($sql, $params) === 0;
    }

    private function exists(string $arg, mixed $value): bool
    {
        [$table, $column] = array_pad(explode(',', $arg), 2, 'id');
        $db = $this->db ?? App::db();
        $values = array_values(array_unique(array_map('strval', (array) $value)));
        if ($values === []) {
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $sql = 'SELECT COUNT(*) FROM ' . $db->identifier((string) $table) . ' WHERE ' . $db->identifier((string) $column) . " IN ({$placeholders})";
        return (int) $db->scalar($sql, $values) === count($values);
    }
}
