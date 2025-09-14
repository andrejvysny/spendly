<?php

namespace App\Services\RuleEngine;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\MerchantRepositoryInterface;
use App\Contracts\Repositories\TagRepositoryInterface;
use App\Contracts\RuleEngine\ActionExecutorInterface;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\RuleEngine\ActionType;
use App\Models\RuleEngine\RuleAction;
use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class ActionExecutor implements ActionExecutorInterface
{
    // Cache for frequently accessed models to reduce database queries
    private array $categoryCache = [];
    private array $merchantCache = [];
    private array $tagCache = [];
    private array $transactionUpdateBatch = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly MerchantRepositoryInterface $merchantRepository,
        private readonly TagRepositoryInterface $tagRepository
    ) {}

    public function execute(RuleAction $action, Transaction $transaction): bool
    {
        try {
            return match (ActionType::from($action->action_type)) {
                ActionType::ACTION_SET_CATEGORY => $this->setCategory($action, $transaction),
                ActionType::ACTION_SET_MERCHANT => $this->setMerchant($action, $transaction),
                ActionType::ACTION_ADD_TAG => $this->addTag($action, $transaction),
                ActionType::ACTION_REMOVE_TAG => $this->removeTag($action, $transaction),
                ActionType::ACTION_REMOVE_ALL_TAGS => $this->removeAllTags($transaction),
                ActionType::ACTION_SET_DESCRIPTION => $this->setDescription($action, $transaction),
                ActionType::ACTION_APPEND_DESCRIPTION => $this->appendDescription($action, $transaction),
                ActionType::ACTION_PREPEND_DESCRIPTION => $this->prependDescription($action, $transaction),
                ActionType::ACTION_SET_NOTE => $this->setNote($action, $transaction),
                ActionType::ACTION_APPEND_NOTE => $this->appendNote($action, $transaction),
                ActionType::ACTION_SET_TYPE => $this->setType($action, $transaction),
                ActionType::ACTION_MARK_RECONCILED => $this->markReconciled($transaction),
                ActionType::ACTION_SEND_NOTIFICATION => $this->sendNotification($action, $transaction),
                ActionType::ACTION_CREATE_TAG_IF_NOT_EXISTS => $this->createTagIfNotExists($action, $transaction),
                ActionType::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS => $this->createCategoryIfNotExists($action, $transaction),
                ActionType::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS => $this->createMerchantIfNotExists($action, $transaction),
                default => false,
            };
        } catch (\Exception $e) {
            Log::error('Rule action execution failed', [
                'action_type' => $action->action_type,
                'action_value' => $action->action_value,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function supportsAction(ActionType $actionType): bool
    {
        return in_array($actionType, ActionType::cases());
    }

    public function validateActionValue(ActionType $actionType, mixed $value): bool
    {
        if (in_array($actionType, ActionType::valuelessActions())) {
            return true;
        }

        if (in_array($actionType, ActionType::idBasedActions())) {
            return is_numeric($value) && $value > 0;
        }

        if (in_array($actionType, ActionType::stringBasedActions())) {
            return is_string($value) && $value !== '';
        }

        return false;
    }

    public function getActionDescription(RuleAction $action): string
    {
        $value = $action->getDecodedValue();

        return match (ActionType::from($action->action_type)) {
            ActionType::ACTION_SET_CATEGORY => "Set category to: {$this->getCategoryName($value)}",
            ActionType::ACTION_SET_MERCHANT => "Set merchant to: {$this->getMerchantName($value)}",
            ActionType::ACTION_ADD_TAG => "Add tag: {$this->getTagName($value)}",
            ActionType::ACTION_REMOVE_TAG => "Remove tag: {$this->getTagName($value)}",
            ActionType::ACTION_REMOVE_ALL_TAGS => 'Remove all tags',
            ActionType::ACTION_SET_DESCRIPTION => "Set description to: {$value}",
            ActionType::ACTION_APPEND_DESCRIPTION => "Append to description: {$value}",
            ActionType::ACTION_PREPEND_DESCRIPTION => "Prepend to description: {$value}",
            ActionType::ACTION_SET_NOTE => "Set note to: {$value}",
            ActionType::ACTION_APPEND_NOTE => "Append to note: {$value}",
            ActionType::ACTION_SET_TYPE => "Set type to: {$value}",
            ActionType::ACTION_MARK_RECONCILED => 'Mark as reconciled',
            ActionType::ACTION_SEND_NOTIFICATION => 'Send notification',
            ActionType::ACTION_CREATE_TAG_IF_NOT_EXISTS => "Create tag if not exists: {$value}",
            ActionType::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS => "Create category if not exists: {$value}",
            ActionType::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS => "Create merchant if not exists: {$value}",
            default => 'Unknown action',
        };
    }

    private function setCategory(RuleAction $action, Transaction $transaction): bool
    {
        $categoryId = (int) $action->getDecodedValue();

        // Use cache to avoid repeated database queries
        if (!isset($this->categoryCache[$categoryId])) {
            $category = $this->categoryRepository->find($categoryId);
            $this->categoryCache[$categoryId] = $category;
        } else {
            $category = $this->categoryCache[$categoryId];
        }

        if (!$category || $category->user_id !== $transaction->account->user_id) {
            return false;
        }

        $transaction->category_id = $categoryId;
        $transaction->save();

        return true;
    }

    private function setMerchant(RuleAction $action, Transaction $transaction): bool
    {
        $merchantId = (int) $action->getDecodedValue();

        // Use cache to avoid repeated database queries
        if (!isset($this->merchantCache[$merchantId])) {
            $merchant = $this->merchantRepository->find($merchantId);
            $this->merchantCache[$merchantId] = $merchant;
        } else {
            $merchant = $this->merchantCache[$merchantId];
        }

        if (! $merchant || $merchant->user_id !== $transaction->account->user_id) {
            return false;
        }

        $transaction->merchant_id = $merchantId;
        $transaction->save();

        return true;
    }

    private function addTag(RuleAction $action, Transaction $transaction): bool
    {
        $tagId = (int) $action->getDecodedValue();

        // Use cache to avoid repeated database queries
        if (!isset($this->tagCache[$tagId])) {
            $tag = $this->tagRepository->find($tagId);
            $this->tagCache[$tagId] = $tag;
        } else {
            $tag = $this->tagCache[$tagId];
        }

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
        $tagId = (int) $action->getDecodedValue();
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

        $transaction->type = $newType;
        $transaction->save();

        return true;
    }

    private function markReconciled(Transaction $transaction): bool
    {
        // Assuming there's a reconciled field - adjust based on actual schema
        $transaction->markReconciled("Marked reconciled by rule engine");
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

        $transaction->setCategory($category);
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

        $transaction->setMerchant($merchant);
        $transaction->save();

        return true;
    }

    private function getCategoryName($categoryId): string
    {
        if (! is_numeric($categoryId)) {
            return $categoryId;
        }

        // Use cache to avoid repeated database queries
        if (!isset($this->categoryCache[$categoryId])) {
            $category = Category::find($categoryId);
            $this->categoryCache[$categoryId] = $category;
        } else {
            $category = $this->categoryCache[$categoryId];
        }

        return $category ? $category->name : "Category #{$categoryId}";
    }

    private function getMerchantName(int $merchantId): string
    {
        // Use cache to avoid repeated database queries
        if (!isset($this->merchantCache[$merchantId])) {
            $merchant = Merchant::find($merchantId);
            $this->merchantCache[$merchantId] = $merchant;
        } else {
            $merchant = $this->merchantCache[$merchantId];
        }

        return $merchant ? $merchant->name : "Merchant #{$merchantId}";
    }

    private function getTagName($tagId): string
    {
        if (! is_numeric($tagId)) {
            return $tagId;
        }

        // Use cache to avoid repeated database queries
        if (!isset($this->tagCache[$tagId])) {
            $tag = Tag::find($tagId);
            $this->tagCache[$tagId] = $tag;
        } else {
            $tag = $this->tagCache[$tagId];
        }

        return $tag ? $tag->name : "Tag #{$tagId}";
    }

    /**
     * Clear all caches to free memory.
     */
    public function clearCaches(): self
    {
        $this->categoryCache = [];
        $this->merchantCache = [];
        $this->tagCache = [];
        $this->transactionUpdateBatch = [];

        return $this;
    }

    /**
     * Get cache statistics for debugging.
     */
    public function getCacheStats(): array
    {
        return [
            'category_cache_count' => count($this->categoryCache),
            'merchant_cache_count' => count($this->merchantCache),
            'tag_cache_count' => count($this->tagCache),
            'pending_updates' => count($this->transactionUpdateBatch),
        ];
    }
}
