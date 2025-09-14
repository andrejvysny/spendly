<?php

namespace App\Models\RuleEngine;

enum ActionType: string
{
    case ACTION_SET_CATEGORY = 'set_category';

    case ACTION_SET_MERCHANT = 'set_merchant';

    case ACTION_ADD_TAG = 'add_tag';

    case ACTION_REMOVE_TAG = 'remove_tag';

    case ACTION_REMOVE_ALL_TAGS = 'remove_all_tags';

    case ACTION_SET_DESCRIPTION = 'set_description';

    case ACTION_APPEND_DESCRIPTION = 'append_description';

    case ACTION_PREPEND_DESCRIPTION = 'prepend_description';

    case ACTION_SET_NOTE = 'set_note';

    case ACTION_APPEND_NOTE = 'append_note';

    case ACTION_SET_TYPE = 'set_type';

    case ACTION_MARK_RECONCILED = 'mark_reconciled';

    case ACTION_SEND_NOTIFICATION = 'send_notification';

    case ACTION_CREATE_TAG_IF_NOT_EXISTS = 'create_tag_if_not_exists';

    case ACTION_CREATE_CATEGORY_IF_NOT_EXISTS = 'create_category_if_not_exists';

    case ACTION_CREATE_MERCHANT_IF_NOT_EXISTS = 'create_merchant_if_not_exists';

    public static function value(): string
    {
        return self::class;
    }

    public static function idBasedActions(): array
    {
        return [
            self::ACTION_SET_CATEGORY,
            self::ACTION_SET_MERCHANT,
            self::ACTION_ADD_TAG,
            self::ACTION_REMOVE_TAG,
        ];
    }

    public static function stringBasedActions(): array
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

    public static function valuelessActions(): array
    {
        return [
            self::ACTION_REMOVE_ALL_TAGS,
            self::ACTION_MARK_RECONCILED,
        ];
    }
}
