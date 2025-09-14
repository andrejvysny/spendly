<?php

namespace Tests\Unit\RuleEngine;

use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\RuleEngine\ActionType;
use App\Models\RuleEngine\RuleAction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RuleEngine\ActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private ActionExecutor $executor;

    private Transaction $transaction;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new ActionExecutor;

        // Create test data
        $this->user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $this->user->id]);
        $this->transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'description' => 'Original Description',
            'note' => 'Original Note',
            'amount' => 100.00,
        ]);
    }


    public function it_sets_category()
    {
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_CATEGORY,
        ]);
        $action->setEncodedValue($category->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals($category->id, $this->transaction->fresh()->category_id);
    }


    public function it_fails_to_set_category_from_different_user()
    {
        $otherCategory = Category::factory()->create(['user_id' => User::factory()->create()->id]);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_CATEGORY,
        ]);
        $action->setEncodedValue($otherCategory->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertFalse($result);
        $this->assertNull($this->transaction->fresh()->category_id);
    }


    public function it_sets_merchant()
    {
        $merchant = Merchant::factory()->create(['user_id' => $this->user->id]);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_MERCHANT,
        ]);
        $action->setEncodedValue($merchant->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals($merchant->id, $this->transaction->fresh()->merchant_id);
    }


    public function it_adds_tag()
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_ADD_TAG,
        ]);
        $action->setEncodedValue($tag->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->transaction->refresh();
        $this->assertTrue($this->transaction->tags->contains($tag));
    }


    public function it_does_not_duplicate_tags()
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $this->transaction->tags()->attach($tag);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_ADD_TAG,
        ]);
        $action->setEncodedValue($tag->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->transaction->tags()->count());
    }


    public function it_removes_tag()
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $this->transaction->tags()->attach($tag);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_REMOVE_TAG,
        ]);
        $action->setEncodedValue($tag->id);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertFalse($this->transaction->fresh()->tags->contains($tag));
    }


    public function it_removes_all_tags()
    {
        $tags = Tag::factory()->count(3)->create(['user_id' => $this->user->id]);
        $this->transaction->tags()->attach($tags);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_REMOVE_ALL_TAGS,
        ]);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals(0, $this->transaction->fresh()->tags()->count());
    }


    public function it_sets_description()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_DESCRIPTION,
        ]);
        $action->setEncodedValue('New Description');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals('New Description', $this->transaction->fresh()->description);
    }


    public function it_appends_to_description()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_APPEND_DESCRIPTION,
        ]);
        $action->setEncodedValue(' - Appended');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals('Original Description - Appended', $this->transaction->fresh()->description);
    }


    public function it_prepends_to_description()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_PREPEND_DESCRIPTION,
        ]);
        $action->setEncodedValue('Prepended - ');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals('Prepended - Original Description', $this->transaction->fresh()->description);
    }


    public function it_sets_note()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_NOTE,
        ]);
        $action->setEncodedValue('New Note');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals('New Note', $this->transaction->fresh()->note);
    }


    public function it_appends_to_note()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_APPEND_NOTE,
        ]);
        $action->setEncodedValue(' - Additional info');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals('Original Note - Additional info', $this->transaction->fresh()->note);
    }


    public function it_sets_type()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_TYPE,
        ]);
        $action->setEncodedValue(Transaction::TYPE_TRANSFER);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals(Transaction::TYPE_TRANSFER, $this->transaction->fresh()->type);
    }


    public function it_fails_to_set_invalid_type()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_TYPE,
        ]);
        $action->setEncodedValue('INVALID_TYPE');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertFalse($result);
    }


    public function it_marks_as_reconciled()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_MARK_RECONCILED,
        ]);

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertTrue($this->transaction->fresh()->is_reconciled);
    }


    public function it_sends_notification()
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Rule triggered notification', \Mockery::any());

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SEND_NOTIFICATION,
            'rule_id' => 1,
        ]);
        $action->setEncodedValue('Test notification message');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
    }


    public function it_creates_tag_if_not_exists()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS,
        ]);
        $action->setEncodedValue('New Tag');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);

        $tag = Tag::where('name', 'New Tag')->where('user_id', $this->user->id)->first();
        $this->assertNotNull($tag);
        $this->assertTrue($this->transaction->fresh()->tags->contains($tag));
    }


    public function it_uses_existing_tag_when_creating_if_not_exists()
    {
        $existingTag = Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Existing Tag',
        ]);

        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS,
        ]);
        $action->setEncodedValue('Existing Tag');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);
        $this->assertEquals(1, Tag::where('name', 'Existing Tag')->count());
        $this->assertTrue($this->transaction->fresh()->tags->contains($existingTag));
    }


    public function it_creates_category_if_not_exists()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
        ]);
        $action->setEncodedValue('New Category');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);

        $category = Category::where('name', 'New Category')->where('user_id', $this->user->id)->first();
        $this->assertNotNull($category);
        $this->assertEquals($category->id, $this->transaction->fresh()->category_id);
    }


    public function it_creates_merchant_if_not_exists()
    {
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS,
        ]);
        $action->setEncodedValue('New Merchant');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertTrue($result);

        $merchant = Merchant::where('name', 'New Merchant')->where('user_id', $this->user->id)->first();
        $this->assertNotNull($merchant);
        $this->assertEquals($merchant->id, $this->transaction->fresh()->merchant_id);
    }


    public function it_handles_action_execution_errors_gracefully()
    {
        // Create action with invalid data
        $action = new RuleAction([
            'action_type' => RuleAction::ACTION_SET_CATEGORY,
        ]);
        $action->setEncodedValue('invalid_id');

        $result = $this->executor->execute($action, $this->transaction);

        $this->assertFalse($result);
    }


    public function it_validates_action_values()
    {
        // ID-based action
        $this->assertTrue($this->executor->validateActionValue(ActionType::ACTION_SET_CATEGORY, 123));
        $this->assertFalse($this->executor->validateActionValue(ActionType::ACTION_SET_CATEGORY, 'not_a_number'));

        // String-based action
        $this->assertTrue($this->executor->validateActionValue(ActionType::ACTION_SET_DESCRIPTION, 'Valid string'));
        $this->assertFalse($this->executor->validateActionValue(ActionType::ACTION_SET_DESCRIPTION, ''));

        // Valueless action
        $this->assertTrue($this->executor->validateActionValue(ActionType::ACTION_REMOVE_ALL_TAGS, null));
        $this->assertTrue($this->executor->validateActionValue(ActionType::ACTION_REMOVE_ALL_TAGS, 'any value'));
    }


    public function it_generates_action_descriptions()
    {
        $category = Category::factory()->create(['name' => 'Test Category']);

        $action = new RuleAction([
            'action_type' => ActionType::ACTION_SET_CATEGORY,
        ]);
        $action->setEncodedValue($category->id);

        $description = $this->executor->getActionDescription($action);
        $this->assertStringContainsString('Set category to', $description);
        $this->assertStringContainsString('Test Category', $description);
    }


    public function it_supports_all_defined_action_types()
    {
        $actionTypes = RuleAction::getActionTypes();

        foreach ($actionTypes as $actionType) {
            $this->assertTrue(
                $this->executor->supportsAction($actionType),
                "ActionType type {$actionType} should be supported"
            );
        }

        $this->assertFalse($this->executor->supportsAction('invalid_action'));
    }
}
