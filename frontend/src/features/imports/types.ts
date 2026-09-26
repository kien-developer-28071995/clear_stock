export type ImportField =
    | 'po_number'
    | 'supplier'
    | 'sku'
    | 'variant_id'
    | 'product'
    | 'variant'
    | 'ordered_at'
    | 'expected_at'
    | 'received_at';

export const IMPORT_FIELDS: ImportField[] = ['supplier', 'sku', 'variant_id', 'product', 'variant', 'po_number', 'ordered_at', 'expected_at', 'received_at'];

export type ImportMapping = Record<ImportField, string | null>;

export interface ImportSupplier {
    name: string;
    existing: boolean;
    current_lead_time_days: number | null;
    products: number;
    purchase_orders: number;
    /** Median days from ordered to received (or expected); null without dates. */
    lead_time_days: number | null;
    lead_time_samples: number;
}

export interface ImportPreview {
    /** Column headers found in the files (the merchant's own data). */
    columns: string[];
    mapping: ImportMapping;
    /** Required fields without a column: 'supplier' and/or 'product'. */
    missing: ('supplier' | 'product')[];
    rows: number;
    purchase_orders: number;
    suppliers: ImportSupplier[];
    products: { matched: number; unmatched: number; unmatched_samples: string[]; with_supplier: number };
}

export interface ImportResult {
    suppliers_created: number;
    suppliers_updated: number;
    products_assigned: number;
    products_kept: number;
}

export interface ImportRequest {
    files: File[];
    mapping: ImportMapping | null;
}

export interface ApplyRequest extends ImportRequest {
    suppliers: { name: string; lead_time_days: number | null }[];
    replaceExisting: boolean;
}
