export type RealtimeAlertMode = 'off' | 'out_of_stock' | 'all';

export interface Settings {
    default_lead_time_days: number;
    default_safety_days: number;
    /** Cap one-off sales spikes before averaging; null when switched off app-wide. */
    filter_sales_spikes: boolean | null;
    /** Default averaging windows (a product's own setting wins). */
    forecast_profile: 'balanced' | 'recent' | 'steady';
    /** Orders left out of the sales history: by order tag, and by source. Changing them re-reads the history. */
    excluded_order_tags: string[];
    excluded_order_sources: ('pos' | 'draft')[];
    /** null = follow the Shopify admin language */
    locale: string | null;
    alerts: {
        available: boolean;
        email: string | null;
        enabled: boolean;
        frequency: 'daily' | 'weekly';
        weekly_day: number;
        /** Growth: live stock emails, batched and capped (see backend config/alerts.php). */
        realtime_available: boolean;
        realtime: RealtimeAlertMode;
        /** One summary email a week (every plan); null when switched off app-wide. */
        weekly_summary: boolean | null;
        /** Slack incoming webhook the reorder digest is also posted to. */
        slack_webhook_url: string | null;
        /** Also alert at this many days of stock left or fewer (null = reorder date only). */
        cover_days: number | null;
    };
    /** Shopify Flow triggers (Growth); `active` = a workflow in Flow uses one of them. */
    flow: { available: boolean; active: boolean };
}

/** A location and whether its stock is left out of the forecasts (returns, damaged goods, showroom). */
export interface StockLocation {
    id: number;
    name: string;
    excluded: boolean;
}

export interface Supplier {
    id: number;
    name: string;
    email: string | null;
    lead_time_days: number | null;
    /** Freight, duty and handling on top of the supplier's price (%): money figures use the landed cost. */
    landed_cost_percent?: number | null;
    /** The supplier's minimum order value (shop currency). */
    min_order_value?: number | null;
    /** What is due to order from this supplier now, at the supplier's price (null = nothing). */
    due?: { products: number; units: number; cost: number } | null;
    /** Median days real deliveries took (orders marked as ordered, then received); null with too few. */
    actual_lead_time?: { median_days: number; orders: number } | null;
    /** Defaults for this supplier's products (a product's own setting wins). */
    min_order_qty: number | null;
    pack_size: number | null;
    /** How often orders go to this supplier: an order covers this many days of sales (null = app default). */
    order_cycle_days: number | null;
    /** ISO weekdays orders are placed on (1 = Monday); null = any day. */
    order_weekdays: number[] | null;
    variants_count: number | null;
    /** Growth: purchase orders emailed automatically when this supplier's products are due. */
    auto_email: boolean;
    last_emailed_at: string | null;
}

export type SupplierInput = Pick<Supplier, 'name' | 'email' | 'lead_time_days' | 'min_order_qty' | 'pack_size' | 'order_cycle_days' | 'order_weekdays'> & Partial<Pick<Supplier, 'landed_cost_percent' | 'min_order_value'>> & { auto_email?: boolean };

/** A Shopify vendor that can become a supplier. */
export interface VendorCandidate {
    vendor: string;
    products: number;
    /** Products of this vendor already linked to a supplier. */
    with_supplier: number;
    /** Existing supplier with the same name (reused). */
    supplier: { id: number; name: string } | null;
    /** Own-brand products carry the store name as vendor: unticked by default. */
    is_store_name: boolean;
}

export interface VendorImportResult {
    suppliers_created: number;
    suppliers_reused: number;
    products_assigned: number;
    products_kept: number;
}

export interface SupplierEmailItem {
    variant_id: number;
    name: string;
    sku: string | null;
    quantity: number;
}

/** What a purchase order email to a supplier would contain (editable before sending). */
export interface SupplierEmailDraft {
    to: string | null;
    reply_to: string | null;
    items: SupplierEmailItem[];
    last_emailed_at: string | null;
}

export interface SupplierEmailInput {
    id: number;
    items: { variant_id: number; quantity: number }[];
    message: string | null;
    reply_to: string | null;
}

export interface BundleComponent {
    variant_id: number;
    shopify_variant_id: number | null;
    name: string | null;
    sku: string | null;
    quantity: number;
    source: 'manual' | 'shopify';
}

export interface Bundle {
    variant_id: number;
    shopify_variant_id: number;
    name: string;
    sku: string | null;
    components: BundleComponent[];
    editable: boolean;
    /** Bundles the components on hand make, and the component that runs out first. */
    buildable: number | null;
    limiting_component: string | null;
}

/** Variants are local ids or Shopify variant gids (App Bridge resource picker). */
export interface BundleInput {
    bundle: number | string;
    components: { variant: number | string; quantity: number }[];
}
