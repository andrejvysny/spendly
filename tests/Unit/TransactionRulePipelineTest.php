<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\TransactionRule;
use App\Models\User;
use App\Services\TransactionRulePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionRulePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_applies_rules_to_transaction(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Main',
            'bank_name' => 'Demo',
            'iban' => 'DE89370400440532013000',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);

        $tag = Tag::create([
            'user_id' => $user->id,
            'name' => 'Food',
            'color' => '#ff0000',
        ]);

        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Groceries',
        ]);

        TransactionRule::create([
            'user_id' => $user->id,
            'name' => 'High amount',
            'trigger_type' => 'created',
            'condition_type' => 'amount',
            'condition_operator' => 'greater_than',
            'condition_value' => '100',
            'action_type' => 'set_type',
            'action_value' => Transaction::TYPE_TRANSFER,
            'is_active' => true,
            'order' => 1,
        ]);

        TransactionRule::create([
            'user_id' => $user->id,
            'name' => 'Pizza tag',
            'trigger_type' => 'created',
            'condition_type' => 'description',
            'condition_operator' => 'contains',
            'condition_value' => 'Pizza',
            'action_type' => 'add_tag',
            'action_value' => $tag->id,
            'is_active' => true,
            'order' => 2,
        ]);

        TransactionRule::create([
            'user_id' => $user->id,
            'name' => 'Market category',
            'trigger_type' => 'created',
            'condition_type' => 'description',
            'condition_operator' => 'contains',
            'condition_value' => 'Market',
            'action_type' => 'set_category',
            'action_value' => $category->id,
            'is_active' => true,
            'order' => 3,
        ]);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => 150,
            'description' => 'Pizza Market purchase',
            'type' => 'PAYMENT',
        ]);

        $pipeline = new TransactionRulePipeline($user->id);
        $pipeline->process($transaction);

        $this->assertEquals(Transaction::TYPE_TRANSFER, $transaction->type);
        $this->assertEquals($category->id, $transaction->category);
        $this->assertDatabaseHas('tag_transaction', [
            'transaction_id' => $transaction->id,
            'tag_id' => $tag->id,
        ]);
    }
}
