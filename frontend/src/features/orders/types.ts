export type ManualOrderState = 'open' | 'late' | 'overdue' | 'received' | 'cancelled';

/** An order placed outside Shopify, counted as stock on the way while open. */
export interface ManualOrder {
    id: number;
    variant_id: number;
    name: string | null;
    sku: string | null;
    supplier: string | null;
    quantity: number;
    ordered_on: string;
    expected_on: string;
    reference: string | null;
    source: 'manual' | 'supplier_email';
    /** open / late: counted as on the way; overdue: no longer counted until updated. */
    state: ManualOrderState;
}

export interface ManualOrderList {
    today: string;
    open: ManualOrder[];
    closed: ManualOrder[];
}

export interface ManualOrderInput {
    items: { variant_id: number; quantity: number }[];
    /** Empty = today + each product's lead time. */
    expected_on?: string | null;
    reference?: string | null;
}
