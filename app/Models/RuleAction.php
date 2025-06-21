<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleAction extends Model
{
    use HasFactory;

    /**
     * Action type constants.
     */
    const ACTION_SET_CATEGORY = 'set_category';
    const ACTION_SET_MERCHANT = 'set_merchant';
    const ACTION_ADD_TAG = 'add_tag';
    const ACTION_REMOVE_TAG = 'remove_tag';
    const ACTION_REMOVE_ALL_TAGS = 'remove_all_tags';
    const ACTION_SET_DESCRIPTION = 'set_description';
    const ACTION_APPEND_DESCRIPTION = 'append_description';
    const ACTION_PREPEND_DESCRIPTION = 'prepend_description';
    const ACTION_SET_NOTE = 'set_note';
    const ACTION_APPEND_NOTE = 'append_note';
    const ACTION_SET_TYPE = 'set_type';
    const ACTION_MARK_RECONCILED = 'mark_reconciled';
    const ACTION_SEND_NOTIFICATION = 'send_notification';
    const ACTION_CREATE_TAG_IF_NOT_EXISTS = 'create_tag_if_not_exists';
    const ACTION_CREATE_CATEGORY_IF_NOT_EXISTS = 'create_category_if_not_exists';
    const ACTION_CREATE_MERCHANT_IF_NOT_EXISTS = 'create_merchant_if_not_exists';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'rule_id',
        'action_type',
        'action_value',
        'order',
        'stop_processing',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stop_processing' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the rule that owns the action.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * Get decoded action value.
     */
    public function getDecodedValue()
    {
        if ($this->action_value === null || $this->action_value === '') {
            return $this->action_value;
        }

        $decoded = json_decode($this->action_value, true);

        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Return the raw value if JSON decoding fails
            return $this->action_value;
        }

        return $decoded;
    }

    /**
     * Set encoded action value.
     */
    public function setEncodedValue($value): void
    {
        $this->action_value = is_array($value) || is_object($value) 
            ? json_encode($value) 
            : $value;
    }

    /**
     * Get available action types.
     */
    public static function getActionTypes(): array
    {
        return [
            self::ACTION_SET_CATEGORY,
            self::ACTION_SET_MERCHANT,
            self::ACTION_ADD_TAG,
            self::ACTION_REMOVE_TAG,
            self::ACTION_REMOVE_ALL_TAGS,
            self::ACTION_SET_DESCRIPTION,
            self::ACTION_APPEND_DESCRIPTION,
            self::ACTION_PREPEND_DESCRIPTION,
            self::ACTION_SET_NOTE,
            self::ACTION_APPEND_NOTE,
            self::ACTION_SET_TYPE,
            self::ACTION_MARK_RECONCILED,
            self::ACTION_SEND_NOTIFICATION,
            self::ACTION_CREATE_TAG_IF_NOT_EXISTS,
            self::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
            self::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS,
        ];
    }

    /**
     * Get action types that require an ID value.
     */
    public static function getIdBasedActions(): array
    {
        return [
            self::ACTION_SET_CATEGORY,
            self::ACTION_SET_MERCHANT,
            self::ACTION_ADD_TAG,
            self::ACTION_REMOVE_TAG,
        ];
    }

    /**
     * Get action types that require a string value.
     */
    public static function getStringBasedActions(): array
    {
        return [
            self::ACTION_SET_DESCRIPTION,
            self::ACTION_APPEND_DESCRIPTION,
            self::ACTION_PREPEND_DESCRIPTION,
            self::ACTION_SET_NOTE,
            self::ACTION_APPEND_NOTE,
            self::ACTION_SET_TYPE,
            self::ACTION_CREATE_TAG_IF_NOT_EXISTS,
            self::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
            self::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS,
        ];
    }

    /**
     * Get action types that don't require a value.
     */
    public static function getValuelessActions(): array
    {
        return [
            self::ACTION_REMOVE_ALL_TAGS,
            self::ACTION_MARK_RECONCILED,
        ];
    }
} 