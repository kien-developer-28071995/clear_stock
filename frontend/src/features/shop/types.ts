export type Plan = 'free' | 'starter' | 'growth';
export type SyncStatus = 'pending' | 'running' | 'completed' | 'failed';

export interface Shop {
    domain: string;
    name: string | null;
    plan: Plan;
    currency: string | null;
    timezone: string;
    sync_status: SyncStatus;
    sync_error: string | null;
    last_synced_at: string | null;
    installed_at: string | null;
}
