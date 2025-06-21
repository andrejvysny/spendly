<?php

namespace Database\Factories;

use App\Models\ConditionGroup;
use App\Models\RuleCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleCondition>
 */
class RuleConditionFactory extends Factory
{
    protected $model = RuleCondition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $field = $this->faker->randomElement(RuleCondition::getFields());
        $operator = $this->faker->randomElement($this->getOperatorsForField($field));

        return [
            'condition_group_id' => ConditionGroup::factory(),
            'field' => $field,
            'operator' => $operator,
            'value' => $this->getValueForFieldAndOperator($field, $operator),
            'is_case_sensitive' => $this->faker->boolean(30), // 30% case sensitive
            'is_negated' => $this->faker->boolean(10), // 10% negated
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }

    /**
     * Get valid operators for a field.
     */
    private function getOperatorsForField(string $field): array
    {
        $numericFields = ['amount'];
        $stringFields = ['description', 'partner', 'note', 'recipient_note', 'place', 'target_iban', 'source_iban'];
        $dateFields = ['date'];

        if (in_array($field, $numericFields)) {
            return [
                RuleCondition::OPERATOR_EQUALS,
                RuleCondition::OPERATOR_NOT_EQUALS,
                RuleCondition::OPERATOR_GREATER_THAN,
                RuleCondition::OPERATOR_GREATER_THAN_OR_EQUAL,
                RuleCondition::OPERATOR_LESS_THAN,
                RuleCondition::OPERATOR_LESS_THAN_OR_EQUAL,
                RuleCondition::OPERATOR_BETWEEN,
            ];
        }

        if (in_array($field, $dateFields)) {
            return [
                RuleCondition::OPERATOR_EQUALS,
                RuleCondition::OPERATOR_GREATER_THAN,
                RuleCondition::OPERATOR_LESS_THAN,
                RuleCondition::OPERATOR_BETWEEN,
            ];
        }

        // String fields
        return [
            RuleCondition::OPERATOR_EQUALS,
            RuleCondition::OPERATOR_NOT_EQUALS,
            RuleCondition::OPERATOR_CONTAINS,
            RuleCondition::OPERATOR_NOT_CONTAINS,
            RuleCondition::OPERATOR_STARTS_WITH,
            RuleCondition::OPERATOR_ENDS_WITH,
            RuleCondition::OPERATOR_REGEX,
            RuleCondition::OPERATOR_WILDCARD,
            RuleCondition::OPERATOR_IS_EMPTY,
            RuleCondition::OPERATOR_IS_NOT_EMPTY,
        ];
    }

    /**
     * Get appropriate value for field and operator.
     */
    private function getValueForFieldAndOperator(string $field, string $operator): string
    {
        if (in_array($operator, [RuleCondition::OPERATOR_IS_EMPTY, RuleCondition::OPERATOR_IS_NOT_EMPTY])) {
            return '';
        }

        if ($operator === RuleCondition::OPERATOR_BETWEEN) {
            if ($field === 'amount') {
                $min = $this->faker->randomFloat(2, 0, 1000);
                $max = $this->faker->randomFloat(2, $min, 5000);
                return "{$min},{$max}";
            }
            if ($field === 'date') {
                $min = $this->faker->date();
                $max = $this->faker->dateTimeBetween($min, '+1 year')->format('Y-m-d');
                return "{$min},{$max}";
            }
        }

        return match ($field) {
            'amount' => (string) $this->faker->randomFloat(2, 0, 1000),
            'description' => $this->faker->sentence(),
            'partner' => $this->faker->company(),
            'type' => $this->faker->randomElement(['PAYMENT', 'TRANSFER', 'DEPOSIT', 'EXCHANGE']),
            'note', 'recipient_note' => $this->faker->optional()->sentence() ?? '',
            'place' => $this->faker->city(),
            'target_iban', 'source_iban' => $this->faker->iban(),
            'date' => $this->faker->date(),
            'category' => $this->faker->word(),
            'merchant' => $this->faker->company(),
            'tags' => implode(',', $this->faker->words(3)),
            default => $this->faker->word(),
        };
    }

    /**
     * Create a specific field condition.
     */
    public function field(string $field): static
    {
        return $this->state(fn (array $attributes) => [
            'field' => $field,
        ]);
    }

    /**
     * Create a specific operator condition.
     */
    public function operator(string $operator): static
    {
        return $this->state(fn (array $attributes) => [
            'operator' => $operator,
        ]);
    }

    /**
     * Create a case-sensitive condition.
     */
    public function caseSensitive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_case_sensitive' => true,
        ]);
    }

    /**
     * Create a negated condition.
     */
    public function negated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_negated' => true,
        ]);
    }
} 