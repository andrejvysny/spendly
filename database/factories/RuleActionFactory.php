<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleAction>
 */
class RuleActionFactory extends Factory
{
    protected $model = RuleAction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actionType = $this->faker->randomElement(RuleAction::getActionTypes());

        return [
            'rule_id' => Rule::factory(),
            'action_type' => $actionType,
            'action_value' => $this->getValueForActionType($actionType),
            'order' => $this->faker->numberBetween(0, 10),
            'stop_processing' => $this->faker->boolean(10), // 10% stop processing
        ];
    }

    /**
     * Get appropriate value for action type.
     */
    private function getValueForActionType(string $actionType): ?string
    {
        return match ($actionType) {
            RuleAction::ACTION_SET_CATEGORY,
            RuleAction::ACTION_SET_MERCHANT,
            RuleAction::ACTION_ADD_TAG,
            RuleAction::ACTION_REMOVE_TAG => (string) $this->faker->numberBetween(1, 100),
            
            RuleAction::ACTION_SET_DESCRIPTION,
            RuleAction::ACTION_APPEND_DESCRIPTION,
            RuleAction::ACTION_PREPEND_DESCRIPTION,
            RuleAction::ACTION_SET_NOTE,
            RuleAction::ACTION_APPEND_NOTE,
            RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS,
            RuleAction::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
            RuleAction::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS,
            RuleAction::ACTION_SEND_NOTIFICATION => $this->faker->sentence(),
            
            RuleAction::ACTION_SET_TYPE => $this->faker->randomElement(['PAYMENT', 'TRANSFER', 'DEPOSIT', 'EXCHANGE']),
            
            RuleAction::ACTION_REMOVE_ALL_TAGS,
            RuleAction::ACTION_MARK_RECONCILED => '',
            
            default => $this->faker->word(),
        };
    }

    /**
     * Create a specific action type.
     */
    public function actionType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => $type,
            'action_value' => $this->getValueForActionType($type),
        ]);
    }

    /**
     * Indicate that the action should stop processing.
     */
    public function stopProcessing(): static
    {
        return $this->state(fn (array $attributes) => [
            'stop_processing' => true,
        ]);
    }
} 