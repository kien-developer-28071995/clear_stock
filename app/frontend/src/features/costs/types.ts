export interface CostRow {
    variant_id: number;
    name: string;
    sku: string | null;
    /** Cost in Shopify (from the sync). */
    shopify_cost: number | null;
    /** Cost entered in the app; wins over Shopify's. */
    app_cost: number | null;
    /** The cost every report uses. */
    cost: number | null;
}

export interface CostList {
    counts: { tracked: number; missing: number; overridden: number };
    items: CostRow[];
    limit: number;
}

export interface CostImportResult {
    updated: number;
    unmatched: number;
    invalid: number;
    unmatched_examples: string[];
}
