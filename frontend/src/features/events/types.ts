export type EventScope = 'all' | 'supplier' | 'products';

/** A known change in sales over some days (promotion, Black Friday, closure). */
export interface SalesEvent {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    /** Sales x this on those days (2 = double, 0.5 = half). */
    multiplier: number;
    applies_to: EventScope;
    supplier_id: number | null;
    supplier: string | null;
    variant_ids: number[];
}

export interface SalesEventInput {
    name: string;
    starts_on: string;
    ends_on: string;
    multiplier: number;
    applies_to: EventScope;
    supplier_id?: number | null;
    /** Local ids or Shopify variant gids (resource picker). */
    variant_ids?: (number | string)[] | null;
}
