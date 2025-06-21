// Rule Engine Type Definitions
export interface User {
    id: number;
    name: string;
    email: string;
}

export interface RuleGroup {
    id: number;
    user_id: number;
    name: string;
    description?: string;
    order: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    rules?: Rule[];
}

export interface Rule {
    id: number;
    user_id: number;
    rule_group_id: number;
    name: string;
    description?: string;
    trigger_type: TriggerType;
    stop_processing: boolean;
    order: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    rule_group?: RuleGroup;
    condition_groups?: ConditionGroup[];
    actions?: RuleAction[];
}

export interface ConditionGroup {
    id: number;
    rule_id: number;
    logic_operator: LogicOperator;
    order: number;
    created_at: string;
    updated_at: string;
    conditions?: RuleCondition[];
}

export interface RuleCondition {
    id: number;
    condition_group_id: number;
    field: ConditionField;
    operator: ConditionOperator;
    value: string;
    is_case_sensitive: boolean;
    is_negated: boolean;
    order: number;
    created_at: string;
    updated_at: string;
}

export interface RuleAction {
    id: number;
    rule_id: number;
    action_type: ActionType;
    action_value?: any;
    order: number;
    stop_processing: boolean;
    created_at: string;
    updated_at: string;
}

// Enums and Union Types
export type TriggerType = 'transaction_created' | 'transaction_updated' | 'manual';

export type LogicOperator = 'AND' | 'OR';

export type ConditionField = 
    | 'amount'
    | 'description'
    | 'partner'
    | 'category'
    | 'merchant'
    | 'account'
    | 'type'
    | 'note'
    | 'recipient_note'
    | 'place'
    | 'target_iban'
    | 'source_iban'
    | 'date'
    | 'tags';

export type ConditionOperator = 
    | 'equals'
    | 'not_equals'
    | 'contains'
    | 'not_contains'
    | 'starts_with'
    | 'ends_with'
    | 'greater_than'
    | 'greater_than_or_equal'
    | 'less_than'
    | 'less_than_or_equal'
    | 'regex'
    | 'wildcard'
    | 'is_empty'
    | 'is_not_empty'
    | 'in'
    | 'not_in'
    | 'between';

export type ActionType = 
    | 'set_category'
    | 'set_merchant'
    | 'add_tag'
    | 'remove_tag'
    | 'remove_all_tags'
    | 'set_description'
    | 'append_description'
    | 'prepend_description'
    | 'set_note'
    | 'append_note'
    | 'set_type'
    | 'mark_reconciled'
    | 'send_notification'
    | 'create_tag_if_not_exists'
    | 'create_category_if_not_exists'
    | 'create_merchant_if_not_exists';

// API Response Types
export interface RuleGroupsResponse {
    data: RuleGroup[];
}

export interface RuleResponse {
    data: Rule;
}

export interface RuleOptionsResponse {
    data: {
        trigger_types: TriggerType[];
        fields: ConditionField[];
        operators: ConditionOperator[];
        logic_operators: LogicOperator[];
        action_types: ActionType[];
        field_operators: {
            numeric: ConditionOperator[];
            string: ConditionOperator[];
        };
    };
}

// Form Types for Creating/Updating
export interface CreateRuleGroupForm {
    name: string;
    description?: string;
    order?: number;
    is_active?: boolean;
}

export interface CreateRuleForm {
    rule_group_id: number;
    name: string;
    description?: string;
    trigger_type: TriggerType;
    stop_processing?: boolean;
    order?: number;
    is_active?: boolean;
    condition_groups: CreateConditionGroupForm[];
    actions: CreateRuleActionForm[];
}

export interface CreateConditionGroupForm {
    logic_operator: LogicOperator;
    order?: number;
    conditions: CreateRuleConditionForm[];
}

export interface CreateRuleConditionForm {
    field: ConditionField;
    operator: ConditionOperator;
    value: string;
    is_case_sensitive?: boolean;
    is_negated?: boolean;
    order?: number;
}

export interface CreateRuleActionForm {
    action_type: ActionType;
    action_value?: any;
    order?: number;
    stop_processing?: boolean;
}

// UI Helper Types
export interface FieldOption {
    value: ConditionField;
    label: string;
}

export interface OperatorOption {
    value: ConditionOperator;
    label: string;
}

export interface ActionOption {
    value: ActionType;
    label: string;
}

export interface TriggerOption {
    value: TriggerType;
    label: string;
}

// API Error Response
export interface ApiError {
    message: string;
    errors?: Record<string, string[]>;
}

// Statistics Types
export interface RuleStatistics {
    total_executions: number;
    total_matches: number;
    match_rate: number;
    last_matched?: string;
    last_executed?: string;
}

export interface RuleStatisticsResponse {
    data: RuleStatistics;
} 