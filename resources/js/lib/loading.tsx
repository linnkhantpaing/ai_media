import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type PropsWithChildren } from 'react';
import { LoadingOverlay } from '@/components/loading-overlay';

type LoadingTask = { id: symbol; message?: string };
type LoadingContextValue = { begin: (message?: string) => () => void };
const LoadingContext = createContext<LoadingContextValue | null>(null);

export function LoadingProvider({ children }: PropsWithChildren) {
    const [tasks, setTasks] = useState<LoadingTask[]>([]);
    const begin = useCallback((message?: string) => {
        const id = Symbol('loading');
        let finished = false;
        setTasks(current => [...current, { id, message }]);
        return () => {
            if (finished) return;
            finished = true;
            setTasks(current => current.filter(task => task.id !== id));
        };
    }, []);
    const value = useMemo(() => ({ begin }), [begin]);
    useEffect(() => {
        const visits = new Map<string, () => void>();
        const offStart = router.on('start', event => {
            const visit = event.detail.visit;
            if (!visit.showProgress) return;
            visits.set(visit.id, begin());
        });
        const offFinish = router.on('finish', event => {
            const id = event.detail.visit.id;
            visits.get(id)?.();
            visits.delete(id);
        });
        return () => { offStart(); offFinish(); visits.forEach(finish => finish()); };
    }, [begin]);
    return <LoadingContext value={value}>{children}<LoadingOverlay active={tasks.length > 0} message={[...tasks].reverse().find(task => task.message)?.message} /></LoadingContext>;
}

export function useLoading() {
    const context = useContext(LoadingContext);
    if (!context) throw new Error('useLoading requires LoadingProvider');
    return context;
}