export type SetupStepKey = 'import_data' | 'lead_time' | 'review_forecast' | 'suppliers' | 'alerts';
export type TipKey = 'home_actions' | 'home_runway' | 'product_explanation';

export interface SetupStep {
    key: SetupStepKey;
    done: boolean;
    skipped: boolean;
    skippable: boolean;
}

export interface SetupGuideState {
    steps: SetupStep[];
    completed: number;
    total: number;
    dismissed: boolean;
    tips_dismissed: TipKey[];
    context: {
        sync_running: boolean;
        alerts_available: boolean;
        example_variant: { id: number; name: string } | null;
        /** Distinct Shopify vendors: suppliers can be created from them in one step. */
        vendor_count: number;
    };
}
