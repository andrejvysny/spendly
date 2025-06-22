<?php

namespace App\Services\RuleEngine;

use App\Contracts\RuleEngine\ActionExecutorInterface;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\RuleAction;
use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class ActionExecutor implements ActionExecutorInterface
{
    public function execute(RuleAction $action, Transaction $transaction): bool
    {
        try {
            return match ($action->action_type) {
                RuleAction::ACTION_SET_CATEGORY => $this->setCategory($action, $transaction),
                RuleAction::ACTION_SET_MERCHANT => $this->setMerchant($action, $transaction),
                RuleAction::ACTION_ADD_TAG => $this->addTag($action, $transaction),
                RuleAction::ACTION_REMOVE_TAG => $this->removeTag($action, $transaction),
                RuleAction::ACTION_REMOVE_ALL_TAGS => $this->removeAllTags($transaction),
                RuleAction::ACTION_SET_DESCRIPTION => $this->setDescription($action, $transaction),
                RuleAction::ACTION_APPEND_DESCRIPTION => $this->appendDescription($action, $transaction),
                RuleAction::ACTION_PREPEND_DESCRIPTION => $this->prependDescription($action, $transaction),
                RuleAction::ACTION_SET_NOTE => $this->setNote($action, $transaction),
                RuleAction::ACTION_APPEND_NOTE => $this->appendNote($action, $transaction),
                RuleAction::ACTION_SET_TYPE => $this->setType($action, $transaction),
                RuleAction::ACTION_MARK_RECONCILED => $this->markReconciled($transaction),
                RuleAction::ACTION_SEND_NOTIFICATION => $this->sendNotification($action, $transaction),
                RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS => $this->createTagIfNotExists($action, $transaction),
                RuleAction::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS => $this->createCategoryIfNotExists($action, $transaction),
                RuleAction::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS => $this->createMerchantIfNotExists($action, $transaction),
                default => false,
            };
        } catch (\Exception $e) {
            Log::error('Rule action execution failed', [
                'action_type' => $action->action_type,
                'action_value' => $action->action_value,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function supportsAction(string $actionType): bool
    {
        return in_array($actionType, RuleAction::getActionTypes());
    }

    public function validateActionValue(string $actionType, mixed $value): bool
    {
        if (in_array($actionType, RuleAction::getValuelessActions())) {
            return true;
        }

        if (in_array($actionType, RuleAction::getIdBasedActions())) {
            return is_numeric($value) && $value > 0;
        }

        if (in_array($actionType, RuleAction::getStringBasedActions())) {
            return is_string($value) && $value !== '';
        }

        return false;
    }

    public function getActionDescription(RuleAction $action): string
    {
        $value = $action->getDecodedValue();

        return match ($action->action_type) {
            RuleAction::ACTION_SET_CATEGORY => "Set category to: {$this->getCategoryName($value)}",
            RuleAction::ACTION_SET_MERCHANT => "Set merchant to: {$this->getMerchantName($value)}",
            RuleAction::ACTION_ADD_TAG => "Add tag: {$this->getTagName($value)}",
            RuleAction::ACTION_REMOVE_TAG => "Remove tag: {$this->getTagName($value)}",
            RuleAction::ACTION_REMOVE_ALL_TAGS => 'Remove all tags',
            RuleAction::ACTION_SET_DESCRIPTION => "Set description to: {$value}",
            RuleAction::ACTION_APPEND_DESCRIPTION => "Append to description: {$value}",
            RuleAction::ACTION_PREPEND_DESCRIPTION => "Prepend to description: {$value}",
            RuleAction::ACTION_SET_NOTE => "Set note to: {$value}",
            RuleAction::ACTION_APPEND_NOTE => "Append to note: {$value}",
            RuleAction::ACTION_SET_TYPE => "Set type to: {$value}",
            RuleAction::ACTION_MARK_RECONCILED => 'Mark as reconciled',
            RuleAction::ACTION_SEND_NOTIFICATION => 'Send notification',
            RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS => "Create tag if not exists: {$value}",
            RuleAction::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS => "Create category if not exists: {$value}",
            RuleAction::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS => "Create merchant if not exists: {$value}",
            default => 'Unknown action',
        };
    }

    private function setCategory(RuleAction $action, Transaction $transaction): bool
    {
        $categoryId = $action->getDecodedValue();
        $category = Category::find($categoryId);

        if (! $category || $category->user_id !== $transaction->account->user_id) {
            return false;
        }

        $transaction->category_id = $categoryId;
        $transaction->save();

        return true;
    }

    private function setMerchant(RuleAction $action, Transaction $transaction): bool
    {
        $merchantId = $action->getDecodedValue();
        $merchant = Merchant::find($merchantId);

        if (! $merchant || $merchant->user_id !== $transaction->account->user_id) {
            return false;
        }

        $transaction->merchant_id = $merchantId;
        $transaction->save();

        return true;
    }

    private function addTag(RuleAction $action, Transaction $transaction): bool
    {
        $tagId = $action->getDecodedValue();
        $tag = Tag::find($tagId);

        if (! $tag || $tag->user_id !== $transaction->account->user_id) {
            return false;
        }

        if (! $transaction->tags->contains($tagId)) {
            $transaction->tags()->attach($tagId);
        }

        return true;
    }

    private function removeTag(RuleAction $action, Transaction $transaction): bool
    {
        $tagId = $action->getDecodedValue();
        $transaction->tags()->detach($tagId);

        return true;
    }

    private function removeAllTags(Transaction $transaction): bool
    {
        $transaction->tags()->detach();

        return true;
    }

    private function setDescription(RuleAction $action, Transaction $transaction): bool
    {
        $transaction->description = $action->getDecodedValue();
        $transaction->save();

        return true;
    }

    private function appendDescription(RuleAction $action, Transaction $transaction): bool
    {
        $transaction->description = ($transaction->description ?? '').$action->getDecodedValue();
        $transaction->save();

        return true;
    }

    private function prependDescription(RuleAction $action, Transaction $transaction): bool
    {
        $transaction->description = $action->getDecodedValue().($transaction->description ?? '');
        $transaction->save();

        return true;
    }

    private function setNote(RuleAction $action, Transaction $transaction): bool
    {
        $transaction->note = $action->getDecodedValue();
        $transaction->save();

        return true;
    }

    private function appendNote(RuleAction $action, Transaction $transaction): bool
    {
        $transaction->note = ($transaction->note ?? '').$action->getDecodedValue();
        $transaction->save();

        return true;
    }

    private function setType(RuleAction $action, Transaction $transaction): bool
    {
        $newType = $action->getDecodedValue();

        // Validate type
        $validTypes = [
            Transaction::TYPE_TRANSFER,
            Transaction::TYPE_CARD_PAYMENT,
            Transaction::TYPE_EXCHANGE,
            Transaction::TYPE_PAYMENT,
            Transaction::TYPE_WITHDRAWAL,
            Transaction::TYPE_DEPOSIT,
        ];

        if (! in_array($newType, $validTypes)) {
            return false;
        }

        $transaction->type = $newType;
        $transaction->save();

        return true;
    }

    private function markReconciled(Transaction $transaction): bool
    {
        // Assuming there's a reconciled field - adjust based on actual schema
        $transaction->is_reconciled = true;
        $transaction->save();

        return true;
    }

    private function sendNotification(RuleAction $action, Transaction $transaction): bool
    {
        // This would integrate with a notification system
        // For now, just log it
        Log::info('Rule triggered notification', [
            'rule_id' => $action->rule_id,
            'transaction_id' => $transaction->id,
            'message' => $action->getDecodedValue() ?? 'Rule matched for transaction',
        ]);

        // In a real implementation, you would send actual notifications here
        // event(new RuleNotification($transaction, $action));

        return true;
    }

    private function createTagIfNotExists(RuleAction $action, Transaction $transaction): bool
    {
        $tagName = $action->getDecodedValue();
        $userId = $transaction->account->user_id;

        $tag = Tag::firstOrCreate(
            ['name' => $tagName, 'user_id' => $userId],
            ['description' => 'Created by rule engine']
        );

        if (! $transaction->tags->contains($tag->id)) {
            $transaction->tags()->attach($tag->id);
        }

        return true;
    }

    private function createCategoryIfNotExists(RuleAction $action, Transaction $transaction): bool
    {
        $categoryName = $action->getDecodedValue();
        $userId = $transaction->account->user_id;

        $category = Category::firstOrCreate(
            ['name' => $categoryName, 'user_id' => $userId],
            ['description' => 'Created by rule engine', 'color' => '#'.dechex(rand(0x000000, 0xFFFFFF))]
        );

        $transaction->category_id = $category->id;
        $transaction->save();

        return true;
    }

    private function createMerchantIfNotExists(RuleAction $action, Transaction $transaction): bool
    {
        $merchantName = $action->getDecodedValue();
        $userId = $transaction->account->user_id;

        $merchant = Merchant::firstOrCreate(
            ['name' => $merchantName, 'user_id' => $userId],
            ['description' => 'Created by rule engine']
        );

        $transaction->merchant_id = $merchant->id;
        $transaction->save();

        return true;
    }

    private function getCategoryName($categoryId): string
    {
        if (! is_numeric($categoryId)) {
            return $categoryId;
        }

        $category = Category::find($categoryId);

        return $category ? $category->name : "Category #{$categoryId}";
    }

    private function getMerchantName($merchantId): string
    {
        if (! is_numeric($merchantId)) {
            return $merchantId;
        }

        $merchant = Merchant::find($merchantId);

        return $merchant ? $merchant->name : "Merchant #{$merchantId}";
    }

    private function getTagName($tagId): string
    {
        if (! is_numeric($tagId)) {
            return $tagId;
        }

        $tag = Tag::find($tagId);

        return $tag ? $tag->name : "Tag #{$tagId}";
    }
}
