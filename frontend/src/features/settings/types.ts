export interface Settings {
    default_lead_time_days: number;
    default_safety_days: number;
    /** null = follow the Shopify admin language */
    locale: string | null;
    alerts: { available: boolean; email: string | null; enabled: boolean; frequency: 'daily' | 'weekly'; weekly_day: number };
}

export interface Supplier {
    id: number;
    name: string;
    email: string | null;
    lead_time_days: number | null;
    variants_count: number | null;
}

export type SupplierInput = Pick<Supplier, 'name' | 'email' | 'lead_time_days'>;

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
