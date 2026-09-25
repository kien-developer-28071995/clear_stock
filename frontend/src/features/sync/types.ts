export type SyncStatusValue = 'pending' | 'running' | 'completed' | 'failed';

export interface SyncRun {
    id: number;
    type: 'initial' | 'nightly' | 'manual';
    stage: string;
    stage_label: string;
    progress: number;
    started_at: string;
    finished_at: string | null;
    stats: { variants: number; orders: number; out_of_stock_days: number } | null;
}

export interface SyncStatus {
    status: SyncStatusValue;
    last_synced_at: string | null;
    error: string | null;
    run: SyncRun | null;
}
