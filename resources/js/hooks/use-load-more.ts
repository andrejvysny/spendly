import { useCallback, useState } from 'react';

export interface FetcherResult<T> {
    data: T[];
    current_page: number;
    has_more_pages?: boolean;
    hasMorePages?: boolean;
    totalCount?: number;
}

interface UseLoadMoreOptions<T, P> {
    initialData: T[];
    initialPage: number;
    initialHasMore: boolean;
    fetcher: (params: P, page: number) => Promise<FetcherResult<T>>;
    initialTotalCount?: number;
}

export function useLoadMore<T, P>({ initialData, initialPage, initialHasMore, fetcher, initialTotalCount }: UseLoadMoreOptions<T, P>) {
    const [data, setData] = useState<T[]>(initialData);
    const [page, setPage] = useState<number>(initialPage);
    const [hasMore, setHasMore] = useState<boolean>(initialHasMore);
    const [isLoadingMore, setIsLoadingMore] = useState<boolean>(false);
    const [totalCount, setTotalCount] = useState<number | undefined>(initialTotalCount);

    const loadMore = useCallback(
        async (params: P) => {
            if (isLoadingMore || !hasMore) return;
            setIsLoadingMore(true);
            try {
                const result = await fetcher(params, page + 1);
                setData((prev) => [...prev, ...(result.data || [])]);
                setPage(result.current_page);
                setHasMore(result.has_more_pages ?? result.hasMorePages ?? false);
                if (typeof result.totalCount === 'number') {
                    setTotalCount(result.totalCount);
                }
            } catch (error) {
                console.error('Error loading more data:', error);
            } finally {
                setIsLoadingMore(false);
            }
        },
        [fetcher, page, hasMore, isLoadingMore],
    );

    const reset = useCallback((newData: T[], newPage: number, hasMorePages: boolean, newTotalCount?: number) => {
        setData(newData);
        setPage(newPage);
        setHasMore(hasMorePages);
        if (typeof newTotalCount === 'number') {
            setTotalCount(newTotalCount);
        }
    }, []);

    return { data, page, hasMore, isLoadingMore, loadMore, reset, totalCount, setData };
}

export default useLoadMore;
