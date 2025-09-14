<?php

namespace Tests\Unit\RuleEngine;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\RuleEngine\ConditionOperator;
use App\Models\RuleEngine\RuleCondition;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\RuleEngine\ConditionEvaluator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator;

        // Create a mock transaction with all fields
        $this->transaction = new Transaction([
            'amount' => 100.50,
            'description' => 'WALMART GROCERY STORE',
            'partner' => 'Walmart Inc.',
            'type' => 'PAYMENT',
            'note' => 'Weekly shopping',
            'recipient_note' => 'Thank you',
            'place' => 'New York',
            'target_iban' => 'DE89370400440532013000',
            'source_iban' => 'GB82WEST12345698765432',
            'booked_date' => Carbon::parse('2024-01-15'),
        ]);
    }

    #[DataProvider('equalsOperatorProvider')]
    public function it_evaluates_equals_operator($field, $value, $expected, $caseSensitive = false)
    {
        $condition = new RuleCondition([
            'field' => $field,
            'operator' => ConditionOperator::OPERATOR_EQUALS,
            'value' => $value,
            'is_case_sensitive' => $caseSensitive,
        ]);

        $result = $this->evaluator->evaluate($condition, $this->transaction);
        $this->assertEquals($expected, $result);
    }

    public static function equalsOperatorProvider()
    {
        return [
            'amount equals exact' => ['amount', '100.50', true],
            'amount equals different' => ['amount', '200', false],
            'description case sensitive' => ['description', 'WALMART GROCERY STORE', true, true],
            'description case insensitive' => ['description', 'walmart grocery store', true, false],
            'type exact match' => ['type', 'PAYMENT', true],
        ];
    }


    public function it_evaluates_not_equals_operator()
    {
        $condition = new RuleCondition([
            'field' => 'amount',
            'operator' => ConditionOperator::OPERATOR_NOT_EQUALS,
            'value' => '200',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    #[DataProvider('containsOperatorProvider')]
    public function it_evaluates_contains_operator($field, $value, $expected, $caseSensitive = false)
    {
        $condition = new RuleCondition([
            'field' => $field,
            'operator' => ConditionOperator::OPERATOR_CONTAINS,
            'value' => $value,
            'is_case_sensitive' => $caseSensitive,
        ]);

        $result = $this->evaluator->evaluate($condition, $this->transaction);
        $this->assertEquals($expected, $result);
    }

    public static function containsOperatorProvider()
    {
        return [
            'description contains WALMART' => ['description', 'WALMART', true],
            'description contains walmart case sensitive' => ['description', 'walmart', false, true],
            'description contains walmart case insensitive' => ['description', 'walmart', true, false],
            'partner contains Inc' => ['partner', 'Inc', true],
        ];
    }


    public function it_evaluates_starts_with_operator()
    {
        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_STARTS_WITH,
            'value' => 'WALMART',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = 'GROCERY';
        $this->assertFalse($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_ends_with_operator()
    {
        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_ENDS_WITH,
            'value' => 'STORE',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    #[DataProvider('numericOperatorProvider')]
    public function it_evaluates_numeric_operators($operator, $value, $expected)
    {
        $condition = new RuleCondition([
            'field' => 'amount',
            'operator' => $operator,
            'value' => $value,
        ]);

        $result = $this->evaluator->evaluate($condition, $this->transaction);
        $this->assertEquals($expected, $result);
    }

    public static function numericOperatorProvider()
    {
        return [
            'greater than 50' => [ConditionOperator::OPERATOR_GREATER_THAN, '50', true],
            'greater than 200' => [ConditionOperator::OPERATOR_GREATER_THAN, '200', false],
            'greater than or equal 100.50' => [ConditionOperator::OPERATOR_GREATER_THAN_OR_EQUAL, '100.50', true],
            'less than 200' => [ConditionOperator::OPERATOR_LESS_THAN, '200', true],
            'less than 50' => [ConditionOperator::OPERATOR_LESS_THAN, '50', false],
            'less than or equal 100.50' => [ConditionOperator::OPERATOR_LESS_THAN_OR_EQUAL, '100.50', true],
        ];
    }


    public function it_evaluates_between_operator()
    {
        $condition = new RuleCondition([
            'field' => 'amount',
            'operator' => ConditionOperator::OPERATOR_BETWEEN,
            'value' => '50,150',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = '200,300';
        $this->assertFalse($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_regex_operator()
    {
        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_REGEX,
            'value' => '/WALMART|TARGET/i',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        // Test without delimiters (should auto-add them)
        $condition->value = 'GROCERY.*STORE';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        // Test non-matching regex
        $condition->value = '/^AMAZON/';
        $this->assertFalse($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_wildcard_operator()
    {
        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_WILDCARD,
            'value' => '*GROCERY*',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = 'WALMART*';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = '*STORE';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = 'W?LMART*';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_is_empty_operator()
    {
        $emptyTransaction = new Transaction(['description' => '', 'note' => null]);

        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_IS_EMPTY,
            'value' => '',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $emptyTransaction));
        $this->assertFalse($this->evaluator->evaluate($condition, $this->transaction));

        $condition->field = 'note';
        $this->assertTrue($this->evaluator->evaluate($condition, $emptyTransaction));
    }


    public function it_evaluates_in_operator()
    {
        $condition = new RuleCondition([
            'field' => 'type',
            'operator' => ConditionOperator::OPERATOR_IN,
            'value' => 'PAYMENT,TRANSFER,DEPOSIT',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->value = 'WITHDRAWAL,EXCHANGE';
        $this->assertFalse($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_date_comparisons()
    {
        $condition = new RuleCondition([
            'field' => 'date',
            'operator' => ConditionOperator::OPERATOR_GREATER_THAN,
            'value' => '2024-01-01',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->operator = ConditionOperator::OPERATOR_LESS_THAN;
        $condition->value = '2024-02-01';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->operator = ConditionOperator::OPERATOR_BETWEEN;
        $condition->value = '2024-01-01,2024-01-31';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_evaluates_tag_conditions()
    {
        // Create tags and associate with transaction
        $tag1 = new Tag(['name' => 'Shopping']);
        $tag2 = new Tag(['name' => 'Groceries']);

        $this->transaction->setRelation('tags', collect([$tag1, $tag2]));

        $condition = new RuleCondition([
            'field' => 'tags',
            'operator' => ConditionOperator::OPERATOR_IN,
            'value' => 'Shopping,Travel',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_handles_category_and_merchant_fields()
    {
        $category = new Category(['name' => 'Groceries']);
        $merchant = new Merchant(['name' => 'Walmart']);

        $this->transaction->setRelation('category', $category);
        $this->transaction->setRelation('merchant', $merchant);

        $condition = new RuleCondition([
            'field' => 'category',
            'operator' => ConditionOperator::OPERATOR_EQUALS,
            'value' => 'Groceries',
        ]);

        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));

        $condition->field = 'merchant';
        $condition->value = 'Walmart';
        $this->assertTrue($this->evaluator->evaluate($condition, $this->transaction));
    }


    public function it_handles_negated_conditions()
    {
        $condition = new RuleCondition([
            'field' => 'amount',
            'operator' => ConditionOperator::OPERATOR_GREATER_THAN,
            'value' => '50',
            'is_negated' => true,
        ]);

        // Amount is 100.50, which is > 50, but negated should make it false
        $result = $this->evaluator->evaluate($condition, $this->transaction);
        $this->assertFalse($result);
    }


    public function it_handles_invalid_regex_gracefully()
    {
        $condition = new RuleCondition([
            'field' => 'description',
            'operator' => ConditionOperator::OPERATOR_REGEX,
            'value' => '[invalid regex',
        ]);

        $result = $this->evaluator->evaluate($condition, $this->transaction);
        $this->assertFalse($result);
    }


    public function it_supports_all_defined_operators()
    {
        $operators = RuleCondition::getOperators();

        foreach ($operators as $operator) {
            $this->assertTrue(
                $this->evaluator->supportsOperator($operator),
                "Operator {$operator} should be supported"
            );
        }

        $this->assertFalse($this->evaluator->supportsOperator('invalid_operator'));
    }
}
