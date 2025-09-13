<?php

namespace App\Services\RuleEngine;

use App\Contracts\RuleEngine\ConditionEvaluatorInterface;
use App\Models\RuleEngine\RuleCondition;
use App\Models\Transaction;
use Carbon\Carbon;

class ConditionEvaluator implements ConditionEvaluatorInterface
{
    public function evaluate(RuleCondition $condition, Transaction $transaction): bool
    {
        $fieldValue = $this->getFieldValue($transaction, $condition->field);
        $conditionValue = $condition->value;
        $caseSensitive = $condition->is_case_sensitive ?? false;

        $result = match ($condition->operator) {
            RuleCondition::OPERATOR_EQUALS => $this->evaluateEquals($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_NOT_EQUALS => ! $this->evaluateEquals($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_CONTAINS => $this->evaluateContains($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_NOT_CONTAINS => ! $this->evaluateContains($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_STARTS_WITH => $this->evaluateStartsWith($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_ENDS_WITH => $this->evaluateEndsWith($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_GREATER_THAN => $this->evaluateGreaterThan($fieldValue, $conditionValue),
            RuleCondition::OPERATOR_GREATER_THAN_OR_EQUAL => $this->evaluateGreaterThanOrEqual($fieldValue, $conditionValue),
            RuleCondition::OPERATOR_LESS_THAN => $this->evaluateLessThan($fieldValue, $conditionValue),
            RuleCondition::OPERATOR_LESS_THAN_OR_EQUAL => $this->evaluateLessThanOrEqual($fieldValue, $conditionValue),
            RuleCondition::OPERATOR_REGEX => $this->evaluateRegex($fieldValue, $conditionValue),
            RuleCondition::OPERATOR_WILDCARD => $this->evaluateWildcard($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_IS_EMPTY => $this->evaluateIsEmpty($fieldValue),
            RuleCondition::OPERATOR_IS_NOT_EMPTY => ! $this->evaluateIsEmpty($fieldValue),
            RuleCondition::OPERATOR_IN => $this->evaluateIn($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_NOT_IN => ! $this->evaluateIn($fieldValue, $conditionValue, $caseSensitive),
            RuleCondition::OPERATOR_BETWEEN => $this->evaluateBetween($fieldValue, $conditionValue),
            default => false,
        };

        // Apply negation if specified
        return $condition->is_negated ? ! $result : $result;
    }

    public function supportsOperator(string $operator): bool
    {
        return in_array($operator, RuleCondition::getOperators());
    }

    public function getFieldValue(Transaction $transaction, string $field): mixed
    {
        return match ($field) {
            RuleCondition::FIELD_AMOUNT => $transaction->amount,
            RuleCondition::FIELD_DESCRIPTION => $transaction->description,
            RuleCondition::FIELD_PARTNER => $transaction->partner,
            RuleCondition::FIELD_CATEGORY => $transaction->category?->name,
            RuleCondition::FIELD_MERCHANT => $transaction->merchant?->name,
            RuleCondition::FIELD_ACCOUNT => $transaction->account?->name,
            RuleCondition::FIELD_TYPE => $transaction->type,
            RuleCondition::FIELD_NOTE => $transaction->note,
            RuleCondition::FIELD_RECIPIENT_NOTE => $transaction->recipient_note,
            RuleCondition::FIELD_PLACE => $transaction->place,
            RuleCondition::FIELD_TARGET_IBAN => $transaction->target_iban,
            RuleCondition::FIELD_SOURCE_IBAN => $transaction->source_iban,
            RuleCondition::FIELD_DATE => $transaction->booked_date,
            RuleCondition::FIELD_TAGS => $transaction->tags->pluck('name')->toArray(),
            default => null,
        };
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
