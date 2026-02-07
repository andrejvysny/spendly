<?php

namespace Database\Factories;

use App\Models\RuleEngine\ActionType;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleEngine\RuleAction>
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
        $actionType = $this->faker->randomElement(ActionType::cases());

        return [
            'rule_id' => Rule::factory(),
            'action_type' => $actionType->value,
            'action_value' => $this->getValueForActionType($actionType),
            'order' => $this->faker->numberBetween(0, 10),
            'stop_processing' => $this->faker->boolean(10), // 10% stop processing
        ];
    }

    /**
     * Get appropriate value for action type.
     */
    private function getValueForActionType(ActionType $actionType): ?string
    {
        return match ($actionType) {
            ActionType::ACTION_SET_CATEGORY,
            ActionType::ACTION_SET_MERCHANT,
            ActionType::ACTION_ADD_TAG,
            ActionType::ACTION_REMOVE_TAG => (string) $this->faker->numberBetween(1, 100),

            ActionType::ACTION_SET_DESCRIPTION,
            ActionType::ACTION_APPEND_DESCRIPTION,
            ActionType::ACTION_PREPEND_DESCRIPTION,
            ActionType::ACTION_SET_NOTE,
            ActionType::ACTION_APPEND_NOTE,
            ActionType::ACTION_CREATE_TAG_IF_NOT_EXISTS,
            ActionType::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
            ActionType::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS,
            ActionType::ACTION_SEND_NOTIFICATION => $this->faker->sentence(),

            ActionType::ACTION_SET_TYPE => $this->faker->randomElement(['PAYMENT', 'TRANSFER', 'DEPOSIT', 'EXCHANGE']),

            ActionType::ACTION_REMOVE_ALL_TAGS,
            ActionType::ACTION_MARK_RECONCILED => '',

            default => $this->faker->word(),
        };
    }

    /**
     * Create a specific action type.
     */
    public function actionType(ActionType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => $type->value,
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
