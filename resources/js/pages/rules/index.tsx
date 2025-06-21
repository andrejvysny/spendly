import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useRulesApi } from '@/hooks/use-rules-api';
import { RuleGroup, Rule } from '@/types/rules';
import { Plus, Edit, Trash2, Copy, MoreHorizontal, ChevronRight, ChevronDown, Power, PowerOff } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { CreateRuleModal } from '@/components/rules/CreateRuleModal';
import { CreateRuleGroupModal } from '@/components/rules/CreateRuleGroupModal';
import axios from 'axios';

interface RulesIndexProps {
    initialRuleGroups: RuleGroup[];
}

export default function RulesIndex({ initialRuleGroups }: RulesIndexProps) {
    const [ruleGroups, setRuleGroups] = useState<RuleGroup[]>(initialRuleGroups || []);
    const [expandedGroups, setExpandedGroups] = useState<Set<number>>(new Set());
    const [selectedRuleForDeletion, setSelectedRuleForDeletion] = useState<Rule | null>(null);
    const [selectedGroupForDeletion, setSelectedGroupForDeletion] = useState<RuleGroup | null>(null);
    const [isCreateRuleModalOpen, setIsCreateRuleModalOpen] = useState(false);
    const [selectedGroupForNewRule, setSelectedGroupForNewRule] = useState<number | undefined>();
    const [isCreateRuleGroupModalOpen, setIsCreateRuleGroupModalOpen] = useState(false);

    const {
        loading,
        error,
        fetchRuleGroups,
        deleteRule,
        deleteRuleGroup,
        duplicateRule,
        toggleRuleGroupActivation,
        clearError,
    } = useRulesApi();

    // Set up initial expanded state
    useEffect(() => {
        if (ruleGroups.length > 0) {
            setExpandedGroups(new Set([ruleGroups[0].id]));
        }
    }, []);

    // Update state when initialRuleGroups prop changes (after Inertia reload)
    useEffect(() => {
        if (initialRuleGroups) {
            setRuleGroups(initialRuleGroups);
        }
    }, [initialRuleGroups]);

    const loadRuleGroups = async () => {
        // Use Inertia reload to get fresh server-side data
        router.reload({
            only: ['initialRuleGroups'],
            onSuccess: (page: any) => {
                if (page.props.initialRuleGroups) {
                    setRuleGroups(page.props.initialRuleGroups);
                }
            },
            onError: async () => {
                // Fallback to API if Inertia reload fails
                const apiGroups = await fetchRuleGroups();
                if (apiGroups) {
                    setRuleGroups(apiGroups);
                }
            }
        });
    };

    const toggleGroup = (groupId: number) => {
        const newExpanded = new Set(expandedGroups);
        if (newExpanded.has(groupId)) {
            newExpanded.delete(groupId);
        } else {
            newExpanded.add(groupId);
        }
        setExpandedGroups(newExpanded);
    };

    const handleDeleteRule = async () => {
        if (!selectedRuleForDeletion) return;

        const success = await deleteRule(selectedRuleForDeletion.id);
        if (success) {
            await loadRuleGroups(); // Refresh data
            setSelectedRuleForDeletion(null);
        }
    };

    const handleDeleteRuleGroup = async () => {
        if (!selectedGroupForDeletion) return;

        const success = await deleteRuleGroup(selectedGroupForDeletion.id);
        if (success) {
            await loadRuleGroups(); // Refresh data
            setSelectedGroupForDeletion(null);
        }
    };

    const handleDuplicateRule = async (rule: Rule) => {
        const newRule = await duplicateRule(rule.id, `${rule.name} (Copy)`);
        if (newRule) {
            await loadRuleGroups(); // Refresh data
        }
    };

    const handleToggleRuleGroupActivation = async (group: RuleGroup) => {
        const updatedGroup = await toggleRuleGroupActivation(group.id);
        if (updatedGroup) {
            await loadRuleGroups(); // Refresh data
        }
    };

    const openCreateRuleModal = (groupId?: number) => {
        setSelectedGroupForNewRule(groupId);
        setIsCreateRuleModalOpen(true);
    };

    const closeCreateRuleModal = () => {
        setIsCreateRuleModalOpen(false);
        setSelectedGroupForNewRule(undefined);
    };

    const handleRuleCreated = () => {
        loadRuleGroups(); // Refresh data
    };

    const openCreateRuleGroupModal = () => {
        setIsCreateRuleGroupModalOpen(true);
    };

    const closeCreateRuleGroupModal = () => {
        setIsCreateRuleGroupModalOpen(false);
    };

    const handleRuleGroupCreated = () => {
        loadRuleGroups(); // Refresh data
    };

    const formatConditionSummary = (rule: Rule): string => {
        if (!rule.condition_groups || rule.condition_groups.length === 0) {
            return 'No conditions';
        }

        const summaries = rule.condition_groups.map(group => {
            if (!group.conditions || group.conditions.length === 0) {
                return '';
            }

            const conditionTexts = group.conditions.map(condition => {
                const operator = condition.operator.replace(/_/g, ' ');
                return `${condition.field} ${operator} "${condition.value}"`;
            });

            return conditionTexts.join(` ${group.logic_operator} `);
        });

        return summaries.filter(s => s).join(' OR ');
    };

    const formatActionSummary = (rule: Rule): string => {
        if (!rule.actions || rule.actions.length === 0) {
            return 'No actions';
        }

        return rule.actions.map(action => {
            const actionType = action.action_type.replace(/_/g, ' ');
            const value = action.action_value || '';
            return `${actionType}${value ? ` "${value}"` : ''}`;
        }).join(', ');
    };

    // Render individual rule group with collapsible rules
    const renderRuleGroup = (group: RuleGroup) => {
        const isExpanded = expandedGroups.has(group.id);
        const rules = group.rules || [];

        return (
            <div key={group.id} className="border rounded-lg mb-5 bg-card">
                <Collapsible open={isExpanded} onOpenChange={() => toggleGroup(group.id)}>
                    <div className="p-4 flex items-center justify-between border-b">
                        <div className="flex items-center gap-3">
                            <CollapsibleTrigger asChild>
                                <Button variant="ghost" size="sm" className="p-0 h-6 w-6">
                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </Button>
                            </CollapsibleTrigger>
                            <div>
                                <h3 className="font-semibold text-lg">{group.name}</h3>
                                {group.description && (
                                    <p className="text-sm text-muted-foreground">{group.description}</p>
                                )}
                            </div>
                            <Badge variant={group.is_active ? 'default' : 'secondary'}>
                                {group.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                            <Badge variant="outline">
                                {rules.length} rule{rules.length !== 1 ? 's' : ''}
                            </Badge>
                        </div>

                                                 <div className="flex items-center gap-2">
                             <Button variant="outline" size="sm" onClick={() => openCreateRuleModal(group.id)}>
                                 <Plus className="h-4 w-4 mr-1" />
                                 Add Rule
                             </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="sm">
                                        <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem>
                                        <Edit className="h-4 w-4 mr-2" />
                                        Edit Group
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={() => handleToggleRuleGroupActivation(group)}>
                                        {group.is_active ? (
                                            <>
                                                <PowerOff className="h-4 w-4 mr-2" />
                                                Deactivate
                                            </>
                                        ) : (
                                            <>
                                                <Power className="h-4 w-4 mr-2" />
                                                Activate
                                            </>
                                        )}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        className="text-destructive"
                                        onClick={() => setSelectedGroupForDeletion(group)}
                                    >
                                        <Trash2 className="h-4 w-4 mr-2" />
                                        Delete Group
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <CollapsibleContent>
                        {rules.length > 0 ? (
                                <DataTable
                                    embedded={true}
                                    columns={[
                                        {
                                            header: 'Rule Name',
                                            key: 'name',
                                            render: (rule: Rule) => (
                                                <div>
                                                    <div className="font-medium">{rule.name}</div>
                                                    {rule.description && (
                                                        <div className="text-sm text-muted-foreground">{rule.description}</div>
                                                    )}
                                                </div>
                                            )
                                        },
                                        {
                                            header: 'Conditions',
                                            key: 'conditions',
                                            render: (rule: Rule) => (
                                                <div className="text-sm max-w-md truncate" title={formatConditionSummary(rule)}>
                                                    {formatConditionSummary(rule)}
                                                </div>
                                            ),
                                        },
                                        {
                                            header: 'Actions',
                                            key: 'rule_actions',
                                            render: (rule: Rule) => (
                                                <div className="text-sm max-w-md truncate" title={formatActionSummary(rule)}>
                                                    {formatActionSummary(rule)}
                                                </div>
                                            ),
                                        },
                                        {
                                            header: 'Trigger',
                                            key: 'trigger_type',
                                            render: (rule: Rule) => (
                                                <Badge variant="outline">
                                                    {rule.trigger_type.replace(/_/g, ' ')}
                                                </Badge>
                                            ),
                                        },
                                        {
                                            header: 'Status',
                                            key: 'status',
                                            render: (rule: Rule) => (
                                                <Badge variant={rule.is_active ? 'default' : 'secondary'}>
                                                    {rule.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            ),
                                        },
                                        {
                                            header: '',
                                            key: 'actions',
                                            className: 'text-right w-32',
                                            render: (rule: Rule) => (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="sm">
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem>
                                                            <Edit className="h-4 w-4 mr-2" />
                                                            Edit
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => handleDuplicateRule(rule)}>
                                                            <Copy className="h-4 w-4 mr-2" />
                                                            Duplicate
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            className="text-destructive"
                                                            onClick={() => setSelectedRuleForDeletion(rule)}
                                                        >
                                                            <Trash2 className="h-4 w-4 mr-2" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            ),
                                        },
                                    ]}
                                    data={rules}
                                    rowKey={(rule) => rule.id}
                                    emptyMessage="No rules in this group"
                                />
                        ) : (
                                                         <div className="p-8 text-center text-muted-foreground">
                                 <p>No rules in this group yet.</p>
                                 <Button variant="outline" className="mt-2" onClick={() => openCreateRuleModal(group.id)}>
                                     <Plus className="h-4 w-4 mr-1" />
                                     Add First Rule
                                 </Button>
                             </div>
                        )}
                    </CollapsibleContent>
                </Collapsible>
            </div>
        );
    };

    return (
        <AppLayout>
            <Head title="Transaction Rules" />

            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                                 <PageHeader
                     title="Transaction Rules"
                     buttons={[
                         {
                             onClick: openCreateRuleGroupModal,
                             label: 'New Rule Group',
                         },
                         {
                             onClick: () => openCreateRuleModal(),
                             label: 'New Rule',
                         },
                     ]}
                 />

                {error && (
                    <Alert className="mb-6" variant="destructive">
                        <AlertDescription>
                            {error}
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-2 h-auto p-0 text-sm underline"
                                onClick={clearError}
                            >
                                Dismiss
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}



                {loading && ruleGroups.length === 0 ? (
                    <div className="text-center py-12">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
                        <p className="mt-4 text-muted-foreground">Loading rules...</p>
                    </div>
                ) : ruleGroups.length === 0 ? (
                    <div className="text-center py-12">
                        <h3 className="text-lg font-semibold mb-2">No rule groups yet</h3>
                        <p className="text-muted-foreground mb-4">
                            Get started by creating your first rule group to organize your transaction rules.
                        </p>
                        <Button onClick={openCreateRuleGroupModal}>
                            <Plus className="h-4 w-4 mr-1" />
                            Create Rule Group
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-4 mt-5">
                        {ruleGroups.map(renderRuleGroup)}
                    </div>
                )}
            </div>
            </div>

            {/* Delete Rule Confirmation Dialog */}
            <AlertDialog open={!!selectedRuleForDeletion} onOpenChange={() => setSelectedRuleForDeletion(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Rule</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete the rule "{selectedRuleForDeletion?.name}"?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDeleteRule} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete Rule
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Delete Rule Group Confirmation Dialog */}
            <AlertDialog open={!!selectedGroupForDeletion} onOpenChange={() => setSelectedGroupForDeletion(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Rule Group</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete the rule group "{selectedGroupForDeletion?.name}" and all its rules?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDeleteRuleGroup} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete Group
                        </AlertDialogAction>
                                    </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        {/* Create Rule Modal */}
        <CreateRuleModal
            isOpen={isCreateRuleModalOpen}
            onClose={closeCreateRuleModal}
            onSuccess={handleRuleCreated}
            ruleGroups={ruleGroups}
            selectedGroupId={selectedGroupForNewRule}
        />

        {/* Create Rule Group Modal */}
        <CreateRuleGroupModal
            isOpen={isCreateRuleGroupModalOpen}
            onClose={closeCreateRuleGroupModal}
            onSuccess={handleRuleGroupCreated}
        />
    </AppLayout>
);
}
