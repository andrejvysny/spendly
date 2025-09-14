<?php

namespace App\Services\RuleEngine;

use App\Contracts\RuleEngine\ConditionEvaluatorInterface;
use App\Models\RuleEngine\ConditionField;
use App\Models\RuleEngine\ConditionOperator;
use App\Models\RuleEngine\RuleCondition;
use App\Models\Transaction;
use Carbon\Carbon;

class ConditionEvaluator implements ConditionEvaluatorInterface
{
    // ConditionField value cache for performance optimization
    private array $fieldValueCache = [];

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    public function evaluate(RuleCondition $condition, Transaction $transaction): bool
    {
        $fieldEnum = ConditionField::from($condition->field);
        $fieldValue = $this->getFieldValue($transaction, $fieldEnum);

        return $this->evaluateWithValue($condition, $fieldValue);
    }

    public function evaluateWithValue(RuleCondition $condition, $fieldValue): bool
    {
        $conditionValue = $condition->value;
        $caseSensitive = $condition->is_case_sensitive ?? false;

        $result = match (ConditionOperator::from($condition->operator)) {
            ConditionOperator::OPERATOR_EQUALS => $this->evaluateEquals($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_NOT_EQUALS => ! $this->evaluateEquals($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_CONTAINS => $this->evaluateContains($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_NOT_CONTAINS => ! $this->evaluateContains($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_STARTS_WITH => $this->evaluateStartsWith($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_ENDS_WITH => $this->evaluateEndsWith($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_GREATER_THAN => $this->evaluateGreaterThan($fieldValue, $conditionValue),
            ConditionOperator::OPERATOR_GREATER_THAN_OR_EQUAL => $this->evaluateGreaterThanOrEqual($fieldValue, $conditionValue),
            ConditionOperator::OPERATOR_LESS_THAN => $this->evaluateLessThan($fieldValue, $conditionValue),
            ConditionOperator::OPERATOR_LESS_THAN_OR_EQUAL => $this->evaluateLessThanOrEqual($fieldValue, $conditionValue),
            ConditionOperator::OPERATOR_REGEX => $this->evaluateRegex($fieldValue, $conditionValue),
            ConditionOperator::OPERATOR_WILDCARD => $this->evaluateWildcard($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_IS_EMPTY => $this->evaluateIsEmpty($fieldValue),
            ConditionOperator::OPERATOR_IS_NOT_EMPTY => ! $this->evaluateIsEmpty($fieldValue),
            ConditionOperator::OPERATOR_IN => $this->evaluateIn($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_NOT_IN => ! $this->evaluateIn($fieldValue, $conditionValue, $caseSensitive),
            ConditionOperator::OPERATOR_BETWEEN => $this->evaluateBetween($fieldValue, $conditionValue),
            default => false,
        };

        // Apply negation if specified
        return $condition->is_negated ? ! $result : $result;
    }

    public function supportsOperator(ConditionOperator $operator): bool
    {
        return in_array($operator, ConditionOperator::cases());
    }

    public function getFieldValue(Transaction $transaction, ConditionField $field): mixed
    {
        // Use cache to avoid repeated calculations
        $cacheKey = $transaction->id.'.'.$field->value;

        if (isset($this->fieldValueCache[$cacheKey])) {
            $this->cacheHits++;

            return $this->fieldValueCache[$cacheKey];
        }

        $this->cacheMisses++;

        $value = match ($field) {
            ConditionField::FIELD_AMOUNT => $transaction->amount,
            ConditionField::FIELD_DESCRIPTION => $transaction->description,
            ConditionField::FIELD_PARTNER => $transaction->partner,
            ConditionField::FIELD_CATEGORY => $transaction->category?->name,
            ConditionField::FIELD_MERCHANT => $transaction->merchant?->name,
            ConditionField::FIELD_ACCOUNT => $transaction->account?->name,
            ConditionField::FIELD_TYPE => $transaction->type,
            ConditionField::FIELD_NOTE => $transaction->note,
            ConditionField::FIELD_RECIPIENT_NOTE => $transaction->recipient_note,
            ConditionField::FIELD_PLACE => $transaction->place,
            ConditionField::FIELD_TARGET_IBAN => $transaction->target_iban,
            ConditionField::FIELD_SOURCE_IBAN => $transaction->source_iban,
            ConditionField::FIELD_DATE => $transaction->booked_date,
            ConditionField::FIELD_TAGS => $transaction->tags->pluck('name')->toArray(),
            default => null,
        };

        // Cache the value
        $this->fieldValueCache[$cacheKey] = $value;

        return $value;
    }

    /**
     * Clear the field value cache to free memory.
     */
    public function clearCache(): self
    {
        $this->fieldValueCache = [];

        return $this;
    }

    /**
     * Get cache statistics.
     */
    public function getCacheStats(): array
    {
        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'hit_ratio' => $this->cacheMisses > 0 ? $this->cacheHits / ($this->cacheHits + $this->cacheMisses) : 1.0,
            'cached_values' => count($this->fieldValueCache),
        ];
    }

    private function evaluateEquals($fieldValue, $conditionValue, bool $caseSensitive): bool
    {
        if ($fieldValue === null) {
            return $conditionValue === '' || $conditionValue === null;
        }

        $fieldValue = (string) $fieldValue;
        $conditionValue = (string) $conditionValue;

        if (! $caseSensitive) {
            return strtolower($fieldValue) === strtolower($conditionValue);
        }

        return $fieldValue === $conditionValue;
    }

    private function evaluateContains($fieldValue, $conditionValue, bool $caseSensitive): bool
    {
        if ($fieldValue === null || $conditionValue === '') {
            return false;
        }

        $fieldValue = (string) $fieldValue;
        $conditionValue = (string) $conditionValue;

        if (! $caseSensitive) {
            return str_contains(strtolower($fieldValue), strtolower($conditionValue));
        }

        return str_contains($fieldValue, $conditionValue);
    }

    private function evaluateStartsWith($fieldValue, $conditionValue, bool $caseSensitive): bool
    {
        if ($fieldValue === null || $conditionValue === '') {
            return false;
        }

        $fieldValue = (string) $fieldValue;
        $conditionValue = (string) $conditionValue;

        if (! $caseSensitive) {
            return str_starts_with(strtolower($fieldValue), strtolower($conditionValue));
        }

        return str_starts_with($fieldValue, $conditionValue);
    }

    private function evaluateEndsWith($fieldValue, $conditionValue, bool $caseSensitive): bool
    {
        if ($fieldValue === null || $conditionValue === '') {
            return false;
        }

        $fieldValue = (string) $fieldValue;
        $conditionValue = (string) $conditionValue;

        if (! $caseSensitive) {
            return str_ends_with(strtolower($fieldValue), strtolower($conditionValue));
        }

        return str_ends_with($fieldValue, $conditionValue);
    }

    private function evaluateGreaterThan($fieldValue, $conditionValue): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Handle date comparisons
        if ($fieldValue instanceof \DateTimeInterface) {
            $conditionDate = Carbon::parse($conditionValue);

            return $fieldValue->greaterThan($conditionDate);
        }

        return (float) $fieldValue > (float) $conditionValue;
    }

    private function evaluateGreaterThanOrEqual($fieldValue, $conditionValue): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Handle date comparisons
        if ($fieldValue instanceof \DateTimeInterface) {
            $conditionDate = Carbon::parse($conditionValue);

            return $fieldValue->greaterThanOrEqualTo($conditionDate);
        }

        return (float) $fieldValue >= (float) $conditionValue;
    }

    private function evaluateLessThan($fieldValue, $conditionValue): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Handle date comparisons
        if ($fieldValue instanceof \DateTimeInterface) {
            $conditionDate = Carbon::parse($conditionValue);

            return $fieldValue->lessThan($conditionDate);
        }

        return (float) $fieldValue < (float) $conditionValue;
    }

    private function evaluateLessThanOrEqual($fieldValue, $conditionValue): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Handle date comparisons
        if ($fieldValue instanceof \DateTimeInterface) {
            $conditionDate = Carbon::parse($conditionValue);

            return $fieldValue->lessThanOrEqualTo($conditionDate);
        }

        return (float) $fieldValue <= (float) $conditionValue;
    }

    private function evaluateRegex($fieldValue, $pattern): bool
    {
        if ($fieldValue === null || $pattern === '') {
            return false;
        }

        $fieldValue = (string) $fieldValue;

        // Ensure the pattern has delimiters
        if (! preg_match('/^[\/~#%].*[\/~#%][imsuxADJSUX]*$/', $pattern)) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/';
        }

        try {
            return preg_match($pattern, $fieldValue) === 1;
        } catch (\Exception $e) {
            // Invalid regex pattern
            return false;
        }
    }

    private function evaluateWildcard($fieldValue, $pattern, bool $caseSensitive): bool
    {
        if ($fieldValue === null || $pattern === '') {
            return false;
        }

        $fieldValue = (string) $fieldValue;

        // Convert wildcard pattern to regex
        $regexPattern = str_replace(
            ['*', '?', '[', ']', '\\'],
            ['.*', '.', '\[', '\]', '\\\\'],
            $pattern
        );

        $regexPattern = '/^'.$regexPattern.'$/';

        if (! $caseSensitive) {
            $regexPattern .= 'i';
        }

        try {
            return preg_match($regexPattern, $fieldValue) === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function evaluateIsEmpty($fieldValue): bool
    {
        if ($fieldValue === null) {
            return true;
        }

        if (is_array($fieldValue)) {
            return empty($fieldValue);
        }

        return trim((string) $fieldValue) === '';
    }

    private function evaluateIn($fieldValue, $conditionValue, bool $caseSensitive): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Parse the condition value as a comma-separated list
        $values = array_map('trim', explode(',', $conditionValue));

        // Handle array field values (like tags)
        if (is_array($fieldValue)) {
            foreach ($fieldValue as $item) {
                if ($this->isValueInList((string) $item, $values, $caseSensitive)) {
                    return true;
                }
            }

            return false;
        }

        return $this->isValueInList((string) $fieldValue, $values, $caseSensitive);
    }

    private function isValueInList(string $value, array $list, bool $caseSensitive): bool
    {
        if (! $caseSensitive) {
            $value = strtolower($value);
            $list = array_map('strtolower', $list);
        }

        return in_array($value, $list, true);
    }

    private function evaluateBetween($fieldValue, $conditionValue): bool
    {
        if ($fieldValue === null) {
            return false;
        }

        // Parse the range (format: "min,max")
        $parts = array_map('trim', explode(',', $conditionValue));

        if (count($parts) !== 2) {
            return false;
        }

        [$min, $max] = $parts;

        // Handle date comparisons
        if ($fieldValue instanceof \DateTimeInterface) {
            $minDate = Carbon::parse($min);
            $maxDate = Carbon::parse($max);

            return $fieldValue->between($minDate, $maxDate);
        }

        $value = (float) $fieldValue;

        return $value >= (float) $min && $value <= (float) $max;
    }
}
