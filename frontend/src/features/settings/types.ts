export type RealtimeAlertMode = 'off' | 'out_of_stock' | 'all';

export interface Settings {
    default_lead_time_days: number;
    default_safety_days: number;
    /** Cap one-off sales spikes before averaging; null when switched off app-wide. */
    filter_sales_spikes: boolean | null;
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
    };
    /** Shopify Flow triggers (Growth); `active` = a workflow in Flow uses one of them. */
    flow: { available: boolean; active: boolean };
}

export interface Supplier {
    id: number;
    name: string;
    email: string | null;
    lead_time_days: number | null;
    /** Defaults for this supplier's products (a product's own setting wins). */
    min_order_qty: number | null;
    pack_size: number | null;
    /** How often orders go to this supplier: an order covers this many days of sales (null = app default). */
    order_cycle_days: number | null;
    variants_count: number | null;
    /** Growth: purchase orders emailed automatically when this supplier's products are due. */
    auto_email: boolean;
    last_emailed_at: string | null;
}

export type SupplierInput = Pick<Supplier, 'name' | 'email' | 'lead_time_days' | 'min_order_qty' | 'pack_size' | 'order_cycle_days'> & { auto_email?: boolean };

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
}

/** Variants are local ids or Shopify variant gids (App Bridge resource picker). */
export interface BundleInput {
    bundle: number | string;
    components: { variant: number | string; quantity: number }[];
}
