<?php

namespace App\Models\RuleEngine;

enum ActionType: string
{
    case ACTION_SET_CATEGORY = 'set_category';

    case ACTION_SET_COUNTERPARTY = 'set_counterparty';

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

    case ACTION_CREATE_COUNTERPARTY_IF_NOT_EXISTS = 'create_counterparty_if_not_exists';

    case ACTION_SET_PARTNER = 'set_partner';

    case ACTION_SET_PLACE = 'set_place';

    case ACTION_MARK_REVIEWED = 'mark_reviewed';

    case ACTION_CLEAR_CATEGORY = 'clear_category';

    case ACTION_CLEAR_COUNTERPARTY = 'clear_counterparty';

    public static function value(): string
    {
        return self::class;
    }

    public static function idBasedActions(): array
    {
        return [
            self::ACTION_SET_CATEGORY,
            self::ACTION_SET_COUNTERPARTY,
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
            self::ACTION_CREATE_COUNTERPARTY_IF_NOT_EXISTS,
            self::ACTION_SET_PARTNER,
            self::ACTION_SET_PLACE,
        ];
    }

    public static function valuelessActions(): array
    {
        return [
            self::ACTION_REMOVE_ALL_TAGS,
            self::ACTION_MARK_RECONCILED,
            self::ACTION_MARK_REVIEWED,
            self::ACTION_CLEAR_CATEGORY,
            self::ACTION_CLEAR_COUNTERPARTY,
        ];
    }
}
