import { useCallback, useEffect } from 'react';
import type { AdminTransactionRow } from '../lib/adminTransactionLabelingTypes';

interface UseShortcutsProps {
    rows: AdminTransactionRow[];
    activeRowId: number | null;
    expandedIds: Set<number>;
    onSetActive: (id: number | null) => void;
    onToggleSelect: (id: number) => void;
    onToggleExpand: (id: number) => void;
    onAcceptML: (id: number) => void;
    onOpenCategory: (id: number) => void;
    onOpenCounterparty: (id: number) => void;
    onToggleFlag: (id: number, flag: string) => void;
    onNextUnlabeled: () => void;
    onSelectSimilar: () => void;
    onClearSelection: () => void;
}

export function useTransactionLabelingShortcuts({
    rows,
    activeRowId,
    onSetActive,
    onToggleSelect,
    onAcceptML,
    onOpenCategory,
    onOpenCounterparty,
    onToggleFlag,
    onNextUnlabeled,
    onSelectSimilar,
    onClearSelection,
}: UseShortcutsProps) {
    const handleKeyDown = useCallback(
        (e: KeyboardEvent) => {
            // Ignore shortcuts when in input/textarea/select
            const target = e.target as HTMLElement;
            if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable) {
                return;
            }

            const currentIndex = activeRowId ? rows.findIndex((r) => r.id === activeRowId) : -1;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < rows.length - 1) {
                        onSetActive(rows[currentIndex + 1].id);
                    }
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        onSetActive(rows[currentIndex - 1].id);
                    }
                    break;

                case ' ':
                    e.preventDefault();
                    if (activeRowId) {
                        onToggleSelect(activeRowId);
                    }
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (activeRowId) {
                        onAcceptML(activeRowId);
                    }
                    break;

                case 'c':
                case 'C':
                    e.preventDefault();
                    if (activeRowId) {
                        onOpenCategory(activeRowId);
                    }
                    break;

                case 'm':
                case 'M':
                    e.preventDefault();
                    if (activeRowId) {
                        onOpenCounterparty(activeRowId);
                    }
                    break;

                case 'r':
                case 'R':
                    e.preventDefault();
                    if (activeRowId) {
                        onToggleFlag(activeRowId, 'is_recurring');
                    }
                    break;

                case 'f':
                case 'F':
                    e.preventDefault();
                    if (activeRowId) {
                        onToggleFlag(activeRowId, 'is_uncertain');
                    }
                    break;

                case 'd':
                case 'D':
                    e.preventDefault();
                    if (activeRowId) {
                        onToggleFlag(activeRowId, 'is_duplicate');
                    }
                    break;

                case 'Tab':
                    e.preventDefault();
                    onNextUnlabeled();
                    break;

                case 'a':
                case 'A':
                    if (e.shiftKey) {
                        e.preventDefault();
                        onSelectSimilar();
                    }
                    break;

                case 'Escape':
                    e.preventDefault();
                    onClearSelection();
                    break;
            }
        },
        [
            rows,
            activeRowId,
            onSetActive,
            onToggleSelect,
            onAcceptML,
            onOpenCategory,
            onOpenCounterparty,
            onToggleFlag,
            onNextUnlabeled,
            onSelectSimilar,
            onClearSelection,
        ],
    );

    useEffect(() => {
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [handleKeyDown]);
}
