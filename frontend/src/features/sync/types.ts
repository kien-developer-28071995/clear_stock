import type { Coded } from '@/types/coded';

export type SyncStatusValue = 'pending' | 'running' | 'completed' | 'failed';

export interface SyncRun {
    id: number;
    type: 'initial' | 'nightly' | 'manual';
    stage: string;
    progress: number;
    started_at: string;
    finished_at: string | null;
    stats: { variants: number; orders: number; out_of_stock_days: number } | null;
}

export interface SyncStatus {
    status: SyncStatusValue;
    last_synced_at: string | null;
    /** Why the last sync failed, translated by the app. */
    error: Coded | null;
    run: SyncRun | null;
}
