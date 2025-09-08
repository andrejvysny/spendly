import {
    ActionInputConfigResponse,
    CreateRuleForm,
    CreateRuleGroupForm,
    Rule,
    RuleGroup,
    RuleGroupsResponse,
    RuleOptionsResponse,
    RuleResponse,
    RuleStatisticsResponse,
} from '@/types/rules';
import axios from 'axios';
import { useCallback, useState } from 'react';

interface UseRulesApiReturn {
    // State
    loading: boolean;
    error: string | null;

    // Rule Groups
    fetchRuleGroups: (activeOnly?: boolean) => Promise<RuleGroup[] | null>;
    createRuleGroup: (data: CreateRuleGroupForm) => Promise<RuleGroup | null>;
    updateRuleGroup: (id: number, data: Partial<CreateRuleGroupForm>) => Promise<RuleGroup | null>;
    deleteRuleGroup: (id: number) => Promise<boolean>;
    toggleRuleGroupActivation: (id: number) => Promise<RuleGroup | null>;

    // Rules
    fetchRule: (id: number) => Promise<Rule | null>;
    createRule: (data: CreateRuleForm) => Promise<Rule | null>;
    updateRule: (id: number, data: Partial<CreateRuleForm>) => Promise<Rule | null>;
    deleteRule: (id: number) => Promise<boolean>;
    duplicateRule: (id: number, newName?: string) => Promise<Rule | null>;
    toggleRuleActivation: (id: number) => Promise<Rule | null>;

    // Options
    fetchRuleOptions: () => Promise<RuleOptionsResponse['data'] | null>;
    fetchActionInputConfig: () => Promise<ActionInputConfigResponse['data'] | null>;

    // Statistics
    fetchRuleStatistics: (id: number, days?: number) => Promise<RuleStatisticsResponse['data'] | null>;

    // Rule Execution
    executeRulesOnTransactions: (transactionIds: number[], ruleIds?: number[], dryRun?: boolean) => Promise<any>;
    executeRulesOnDateRange: (startDate: string, endDate: string, ruleIds?: number[], dryRun?: boolean) => Promise<any>;
    testRule: (transactionIds: number[], ruleData: Omit<CreateRuleForm, 'rule_group_id' | 'name'>) => Promise<any>;
    executeRule: (ruleId: number, dryRun?: boolean) => Promise<any>;
    executeRuleGroup: (groupId: number, dryRun?: boolean) => Promise<any>;

    // Utility
    clearError: () => void;
}

export function useRulesApi(): UseRulesApiReturn {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleError = (err: any) => {
        // Handle authentication errors specifically
        if (err?.response?.status === 401 || err?.message === 'Unauthenticated.') {
            setError('Your session has expired. Please refresh the page and try again.');
            return;
        }

        // Handle axios validation errors
        if (err?.response?.data?.errors) {
            const firstError = Object.values(err.response.data.errors)[0] as string[];
            setError(firstError?.[0] || err.message || 'An error occurred');
        } else if (err?.response?.data?.message) {
            setError(err.response.data.message);
        } else {
            setError(err?.message || 'An error occurred');
        }
    };

    // Rule Groups API
    const fetchRuleGroups = useCallback(async (activeOnly = false): Promise<RuleGroup[] | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get(`/api/rules${activeOnly ? '?active_only=true' : ''}`);
            const data: RuleGroupsResponse = response.data;
            return data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const createRuleGroup = useCallback(async (data: CreateRuleGroupForm): Promise<RuleGroup | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post('/api/rules/groups', data);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const updateRuleGroup = useCallback(async (id: number, data: Partial<CreateRuleGroupForm>): Promise<RuleGroup | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.put(`/api/rules/groups/${id}`, data);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const deleteRuleGroup = useCallback(async (id: number): Promise<boolean> => {
        try {
            setLoading(true);
            setError(null);

            await axios.delete(`/api/rules/groups/${id}`);
            return true;
        } catch (err) {
            handleError(err);
            return false;
        } finally {
            setLoading(false);
        }
    }, []);

    const toggleRuleGroupActivation = useCallback(async (id: number): Promise<RuleGroup | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.patch(`/api/rules/groups/${id}/toggle-activation`);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    // Rules API
    const fetchRule = useCallback(async (id: number): Promise<Rule | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get(`/api/rules/${id}`);
            const result: RuleResponse = response.data;
            return result.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const createRule = useCallback(async (data: CreateRuleForm): Promise<Rule | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post('/api/rules', data);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const updateRule = useCallback(async (id: number, data: Partial<CreateRuleForm>): Promise<Rule | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.put(`/api/rules/${id}`, data);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const deleteRule = useCallback(async (id: number): Promise<boolean> => {
        try {
            setLoading(true);
            setError(null);

            await axios.delete(`/api/rules/${id}`);
            return true;
        } catch (err) {
            handleError(err);
            return false;
        } finally {
            setLoading(false);
        }
    }, []);

    const duplicateRule = useCallback(async (id: number, newName?: string): Promise<Rule | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post(`/api/rules/${id}/duplicate`, newName ? { name: newName } : {});
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const toggleRuleActivation = useCallback(async (id: number): Promise<Rule | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.patch(`/api/rules/${id}/toggle-activation`);
            return response.data.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    // Options API
    const fetchRuleOptions = useCallback(async (): Promise<RuleOptionsResponse['data'] | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get('/api/rules/options');
            const result: RuleOptionsResponse = response.data;
            return result.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const fetchActionInputConfig = useCallback(async (): Promise<ActionInputConfigResponse['data'] | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get('/api/rules/action-input-config');
            const result: ActionInputConfigResponse = response.data;
            return result.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    // Statistics API
    const fetchRuleStatistics = useCallback(async (id: number, days = 30): Promise<RuleStatisticsResponse['data'] | null> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get(`/api/rules/${id}/statistics?days=${days}`);
            const result: RuleStatisticsResponse = response.data;
            return result.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    // Rule Execution API
    const executeRulesOnTransactions = useCallback(async (transactionIds: number[], ruleIds?: number[], dryRun = false): Promise<any> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post('/api/rules/execute/transactions', {
                transaction_ids: transactionIds,
                rule_ids: ruleIds,
                dry_run: dryRun,
            });

            return response.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const executeRulesOnDateRange = useCallback(async (startDate: string, endDate: string, ruleIds?: number[], dryRun = false): Promise<any> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post('/api/rules/execute/date-range', {
                start_date: startDate,
                end_date: endDate,
                rule_ids: ruleIds,
                dry_run: dryRun,
            });

            return response.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const testRule = useCallback(async (transactionIds: number[], ruleData: Omit<CreateRuleForm, 'rule_group_id' | 'name'>): Promise<any> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post('/api/rules/test', {
                transaction_ids: transactionIds,
                ...ruleData,
            });

            return response.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const executeRule = useCallback(async (ruleId: number, dryRun?: boolean): Promise<any> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post(`/api/rules/${ruleId}/execute`, {
                dry_run: dryRun,
            });

            return response.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const executeRuleGroup = useCallback(async (groupId: number, dryRun?: boolean): Promise<any> => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.post(`/api/rules/groups/${groupId}/execute`, {
                dry_run: dryRun,
            });

            return response.data;
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    const clearError = useCallback(() => {
        setError(null);
    }, []);

    return {
        loading,
        error,
        fetchRuleGroups,
        createRuleGroup,
        updateRuleGroup,
        deleteRuleGroup,
        toggleRuleGroupActivation,
        fetchRule,
        createRule,
        updateRule,
        deleteRule,
        duplicateRule,
        toggleRuleActivation,
        fetchRuleOptions,
        fetchActionInputConfig,
        fetchRuleStatistics,
        executeRulesOnTransactions,
        executeRulesOnDateRange,
        testRule,
        executeRule,
        executeRuleGroup,
        clearError,
    };
}
