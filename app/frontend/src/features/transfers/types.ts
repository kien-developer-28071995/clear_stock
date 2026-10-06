export interface TransferItem {
    variant_id: number;
    name: string;
    sku: string | null;
    /** Suggested quantity to move. */
    quantity: number;
    origin_stock: number;
    destination_stock: number;
    destination_days_of_cover: number | null;
    destination_stockout_date: string | null;
}

export interface TransferRoute {
    origin: { id: number; name: string | null };
    destination: { id: number; name: string | null };
    items: TransferItem[];
    total_units: number;
}

export interface RecentTransfer {
    id: number;
    shopify_transfer_id: number;
    name: string;
    origin: string | null;
    destination: string | null;
    total_units: number;
    created_at: string;
}

export interface Transfers {
    /** Forecasts per location exist (Growth, 2+ active locations, fulfillment order access). */
    available: boolean;
    /** The merchant granted the optional `write_inventory_transfers` scope. */
    scope_granted: boolean;
    routes: TransferRoute[];
    /** Drafts created in the last 7 days (their quantities count as moved). */
    recent: RecentTransfer[];
}

export interface TransferInput {
    origin_location_id: number;
    destination_location_id: number;
    items: { variant_id: number; quantity: number }[];
    idempotency_key: string;
}
