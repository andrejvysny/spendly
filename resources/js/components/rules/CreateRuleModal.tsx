import React, { useState, useEffect } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Trash2, Plus } from 'lucide-react';
import { useRulesApi } from '@/hooks/use-rules-api';
import {
    CreateRuleForm,
    CreateConditionGroupForm,
    CreateRuleConditionForm,
    CreateRuleActionForm,
    RuleGroup,
    ConditionField,
    ConditionOperator,
    ActionType,
    TriggerType,
    LogicOperator,
    RuleOptionsResponse,
} from '@/types/rules';

interface CreateRuleModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
    ruleGroups: RuleGroup[];
    selectedGroupId?: number;
}

// Field display names
const FIELD_LABELS: Record<ConditionField, string> = {
    amount: 'Amount',
    description: 'Transaction name',
    partner: 'Partner',
    category: 'Category',
    merchant: 'Merchant',
    account: 'Account',
    type: 'Type',
    note: 'Note',
    recipient_note: 'Recipient note',
    place: 'Place',
    target_iban: 'Target IBAN',
    source_iban: 'Source IBAN',
    date: 'Date',
    tags: 'Tags',
};

// Operator display names
const OPERATOR_LABELS: Record<ConditionOperator, string> = {
    equals: 'Equals',
    not_equals: 'Not equals',
    contains: 'Contains',
    not_contains: 'Does not contain',
    starts_with: 'Starts with',
    ends_with: 'Ends with',
    greater_than: 'Greater than',
    greater_than_or_equal: 'Greater than or equal',
    less_than: 'Less than',
    less_than_or_equal: 'Less than or equal',
    regex: 'Regex',
    wildcard: 'Wildcard',
    is_empty: 'Is empty',
    is_not_empty: 'Is not empty',
    in: 'Is one of',
    not_in: 'Is not one of',
    between: 'Between',
};

// Action display names
const ACTION_LABELS: Record<ActionType, string> = {
    set_category: 'Set category',
    set_merchant: 'Set merchant',
    add_tag: 'Add tag',
    remove_tag: 'Remove tag',
    remove_all_tags: 'Remove all tags',
    set_description: 'Set transaction name',
    append_description: 'Append to transaction name',
    prepend_description: 'Prepend to transaction name',
    set_note: 'Set note',
    append_note: 'Append to note',
    set_type: 'Set type',
    mark_reconciled: 'Mark as reconciled',
    send_notification: 'Send notification',
    create_tag_if_not_exists: 'Create tag if not exists',
    create_category_if_not_exists: 'Create category if not exists',
    create_merchant_if_not_exists: 'Create merchant if not exists',
};

export function CreateRuleModal({ isOpen, onClose, onSuccess, ruleGroups, selectedGroupId }: CreateRuleModalProps) {
    const [ruleName, setRuleName] = useState('');
    const [selectedGroupId_, setSelectedGroupId_] = useState(selectedGroupId || ruleGroups[0]?.id || 0);
    const [triggerType, setTriggerType] = useState<TriggerType>('manual');
    const [conditionGroups, setConditionGroups] = useState<CreateConditionGroupForm[]>([
        {
            logic_operator: 'AND',
            conditions: [{
                field: 'description',
                operator: 'contains',
                value: '',
            }],
        },
    ]);
    const [actions, setActions] = useState<CreateRuleActionForm[]>([
        {
            action_type: 'set_description',
            action_value: '',
        },
    ]);
    const [applyToAll, setApplyToAll] = useState(true);
    const [startDate, setStartDate] = useState('');
    const [ruleOptions, setRuleOptions] = useState<RuleOptionsResponse['data'] | null>(null);

    const { createRule, fetchRuleOptions, loading, error } = useRulesApi();

    // Load rule options when modal opens
    useEffect(() => {
        if (isOpen && !ruleOptions) {
            fetchRuleOptions().then(options => {
                if (options) {
                    setRuleOptions(options);
                } else {
                    // Fallback with basic options if API fails
                    setRuleOptions({
                        trigger_types: ['manual', 'transaction_created', 'transaction_updated'],
                        fields: ['description', 'amount', 'partner', 'category'],
                        operators: ['contains', 'equals', 'greater_than', 'less_than'],
                        logic_operators: ['AND', 'OR'],
                        action_types: ['set_description', 'set_category', 'add_tag'],
                        field_operators: {
                            numeric: ['equals', 'greater_than', 'less_than', 'greater_than_or_equal', 'less_than_or_equal'],
                            string: ['equals', 'contains', 'starts_with', 'ends_with'],
                        },
                    });
                }
            });
        }
    }, [isOpen, ruleOptions, fetchRuleOptions]);

    // Reset form when modal closes
    useEffect(() => {
        if (!isOpen) {
            setRuleName('');
            setSelectedGroupId_(selectedGroupId || ruleGroups[0]?.id || 0);
            setTriggerType('manual');
            setConditionGroups([{
                logic_operator: 'AND',
                conditions: [{ field: 'description', operator: 'contains', value: '' }],
            }]);
            setActions([{ action_type: 'set_description', action_value: '' }]);
            setApplyToAll(true);
            setStartDate('');
        }
    }, [isOpen, selectedGroupId, ruleGroups]);

    const getOperatorsForField = (field: ConditionField): ConditionOperator[] => {
        if (!ruleOptions) return [];
        
        const numericFields: ConditionField[] = ['amount'];
        const isNumeric = numericFields.includes(field);
        
        return isNumeric 
            ? ruleOptions.field_operators.numeric 
            : ruleOptions.field_operators.string;
    };

    const addConditionGroup = () => {
        setConditionGroups([
            ...conditionGroups,
            {
                logic_operator: 'AND',
                conditions: [{ field: 'description', operator: 'contains', value: '' }],
            },
        ]);
    };

    const removeConditionGroup = (groupIndex: number) => {
        setConditionGroups(conditionGroups.filter((_, i) => i !== groupIndex));
    };

    const updateConditionGroup = (groupIndex: number, updates: Partial<CreateConditionGroupForm>) => {
        setConditionGroups(conditionGroups.map((group, i) => 
            i === groupIndex ? { ...group, ...updates } : group
        ));
    };

    const addCondition = (groupIndex: number) => {
        const newCondition: CreateRuleConditionForm = {
            field: 'description',
            operator: 'contains',
            value: '',
        };
        
        updateConditionGroup(groupIndex, {
            conditions: [...conditionGroups[groupIndex].conditions, newCondition],
        });
    };

    const removeCondition = (groupIndex: number, conditionIndex: number) => {
        updateConditionGroup(groupIndex, {
            conditions: conditionGroups[groupIndex].conditions.filter((_, i) => i !== conditionIndex),
        });
    };

    const updateCondition = (groupIndex: number, conditionIndex: number, updates: Partial<CreateRuleConditionForm>) => {
        const updatedConditions = conditionGroups[groupIndex].conditions.map((condition, i) =>
            i === conditionIndex ? { ...condition, ...updates } : condition
        );
        updateConditionGroup(groupIndex, { conditions: updatedConditions });
    };

    const addAction = () => {
        setActions([
            ...actions,
            { action_type: 'set_description', action_value: '' },
        ]);
    };

    const removeAction = (actionIndex: number) => {
        setActions(actions.filter((_, i) => i !== actionIndex));
    };

    const updateAction = (actionIndex: number, updates: Partial<CreateRuleActionForm>) => {
        setActions(actions.map((action, i) => 
            i === actionIndex ? { ...action, ...updates } : action
        ));
    };

    const handleSubmit = async () => {
        if (!ruleName.trim()) return;
        if (conditionGroups.length === 0) return;
        if (actions.length === 0) return;
        if (!selectedGroupId_ || selectedGroupId_ === 0) {
            return;
        }

        const ruleData: CreateRuleForm = {
            rule_group_id: selectedGroupId_,
            name: ruleName.trim(),
            trigger_type: triggerType,
            condition_groups: conditionGroups,
            actions: actions,
            is_active: true,
        };

        const success = await createRule(ruleData);
        if (success) {
            onSuccess();
            onClose();
        }
    };

    if (!ruleOptions) {
        return (
            <Dialog open={isOpen} onOpenChange={onClose}>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Loading Rule Options</DialogTitle>
                        <DialogDescription>
                            Please wait while we load the available options for creating rules.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center justify-center py-8">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
                        <span className="ml-2">Loading...</span>
                    </div>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New transaction rule</DialogTitle>
                    <DialogDescription>
                        Create a new rule to automatically process your transactions based on conditions you set.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-6">
                    {/* Rule Basic Info */}
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="ruleName">Rule Name</Label>
                            <Input
                                id="ruleName"
                                value={ruleName}
                                onChange={(e) => setRuleName(e.target.value)}
                                placeholder="Enter rule name"
                            />
                        </div>
                        <div>
                            <Label htmlFor="ruleGroup">Rule Group</Label>
                            <Select value={selectedGroupId_.toString()} onValueChange={(value) => setSelectedGroupId_(parseInt(value))}>
                                <SelectTrigger>
                                    <SelectValue placeholder={ruleGroups?.length === 0 ? "No rule groups available" : "Select a rule group"} />
                                </SelectTrigger>
                                <SelectContent>
                                    {ruleGroups?.length === 0 ? (
                                        <SelectItem value="0" disabled>
                                            No rule groups found - create one first
                                        </SelectItem>
                                    ) : (
                                        ruleGroups.map((group) => (
                                            <SelectItem key={group.id} value={group.id.toString()}>
                                                {group.name}
                                            </SelectItem>
                                        ))
                                    )}
                                </SelectContent>
                            </Select>
                            {ruleGroups?.length === 0 && (
                                <p className="text-sm text-muted-foreground mt-1">
                                    You need to create a rule group first before creating rules.
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Conditions Section */}
                    <div>
                        <h3 className="text-lg font-semibold mb-4">If transaction</h3>
                        
                        {conditionGroups.map((group, groupIndex) => (
                            <div key={groupIndex} className="border rounded-lg p-4 mb-4 bg-muted/10">
                                {groupIndex > 0 && (
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">OR match</span>
                                            <Select 
                                                value={group.logic_operator} 
                                                onValueChange={(value: LogicOperator) => updateConditionGroup(groupIndex, { logic_operator: value })}
                                            >
                                                <SelectTrigger className="w-24">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="AND">all</SelectItem>
                                                    <SelectItem value="OR">any</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <span className="text-sm font-medium">of the following conditions</span>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeConditionGroup(groupIndex)}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                )}

                                {groupIndex === 0 && (
                                    <div className="flex items-center gap-2 mb-4">
                                        <span className="text-sm font-medium">Match</span>
                                        <Select 
                                            value={group.logic_operator} 
                                            onValueChange={(value: LogicOperator) => updateConditionGroup(groupIndex, { logic_operator: value })}
                                        >
                                            <SelectTrigger className="w-24">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="AND">all</SelectItem>
                                                <SelectItem value="OR">any</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <span className="text-sm font-medium">of the following conditions</span>
                                    </div>
                                )}

                                {group.conditions.map((condition, conditionIndex) => (
                                    <div key={conditionIndex} className="grid grid-cols-12 gap-2 mb-3">
                                        <div className="col-span-3">
                                            <Select 
                                                value={condition.field} 
                                                onValueChange={(value: ConditionField) => {
                                                    const operators = getOperatorsForField(value);
                                                    updateCondition(groupIndex, conditionIndex, { 
                                                        field: value, 
                                                        operator: operators[0] || 'contains' 
                                                    });
                                                }}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ruleOptions.fields.map((field) => (
                                                        <SelectItem key={field} value={field}>
                                                            {FIELD_LABELS[field]}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="col-span-3">
                                            <Select 
                                                value={condition.operator} 
                                                onValueChange={(value: ConditionOperator) => updateCondition(groupIndex, conditionIndex, { operator: value })}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {getOperatorsForField(condition.field).map((operator) => (
                                                        <SelectItem key={operator} value={operator}>
                                                            {OPERATOR_LABELS[operator]}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="col-span-5">
                                            <Input
                                                value={condition.value}
                                                onChange={(e) => updateCondition(groupIndex, conditionIndex, { value: e.target.value })}
                                                placeholder="Enter a value"
                                            />
                                        </div>
                                        <div className="col-span-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => removeCondition(groupIndex, conditionIndex)}
                                                disabled={group.conditions.length === 1 && conditionGroups.length === 1}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}

                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => addCondition(groupIndex)}
                                    className="mt-2"
                                >
                                    <Plus className="h-4 w-4 mr-1" />
                                    Add condition
                                </Button>
                            </div>
                        ))}

                        <Button
                            variant="outline"
                            size="sm"
                            onClick={addConditionGroup}
                        >
                            <Plus className="h-4 w-4 mr-1" />
                            Add condition group
                        </Button>
                    </div>

                    {/* Actions Section */}
                    <div>
                        <h3 className="text-lg font-semibold mb-4">Then</h3>
                        
                        {actions.map((action, actionIndex) => (
                            <div key={actionIndex} className="grid grid-cols-12 gap-2 mb-3">
                                <div className="col-span-5">
                                    <Select 
                                        value={action.action_type} 
                                        onValueChange={(value: ActionType) => updateAction(actionIndex, { action_type: value })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {ruleOptions.action_types.map((actionType) => (
                                                <SelectItem key={actionType} value={actionType}>
                                                    {ACTION_LABELS[actionType]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="col-span-1 flex items-center justify-center">
                                    <span className="text-sm text-muted-foreground">to</span>
                                </div>
                                <div className="col-span-5">
                                    <Input
                                        value={action.action_value || ''}
                                        onChange={(e) => updateAction(actionIndex, { action_value: e.target.value })}
                                        placeholder="Enter a value"
                                    />
                                </div>
                                <div className="col-span-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeAction(actionIndex)}
                                        disabled={actions.length === 1}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        ))}

                        <Button
                            variant="outline"
                            size="sm"
                            onClick={addAction}
                            className="mt-2"
                        >
                            <Plus className="h-4 w-4 mr-1" />
                            Add action
                        </Button>
                    </div>

                    {/* Apply Section */}
                    <div>
                        <h3 className="text-lg font-semibold mb-4">Apply this</h3>
                        
                        <div className="space-y-3">
                            <div className="flex items-center space-x-2">
                                <input
                                    type="radio"
                                    id="applyToAll"
                                    checked={applyToAll}
                                    onChange={() => setApplyToAll(true)}
                                    className="h-4 w-4"
                                />
                                <Label htmlFor="applyToAll">To all past and future transactions</Label>
                            </div>
                            
                            <div className="flex items-center space-x-2">
                                <input
                                    type="radio"
                                    id="applyFromDate"
                                    checked={!applyToAll}
                                    onChange={() => setApplyToAll(false)}
                                    className="h-4 w-4"
                                />
                                <Label htmlFor="applyFromDate">Starting from</Label>
                                <Input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    disabled={applyToAll}
                                    className="w-auto"
                                />
                            </div>
                        </div>
                    </div>

                    {error && (
                        <div className="bg-destructive/10 border border-destructive rounded-lg p-3">
                            <p className="text-sm text-destructive">{error}</p>
                        </div>
                    )}

                    {ruleGroups?.length === 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                            <p className="text-sm text-yellow-800">
                                <strong>No rule groups available.</strong> You need to create a rule group first before creating rules. 
                                Please close this modal and create a rule group.
                            </p>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button 
                        onClick={handleSubmit} 
                        disabled={loading || !ruleName.trim() || ruleGroups?.length === 0}
                    >
                        {loading ? 'Creating...' : 'Create Rule'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
} 