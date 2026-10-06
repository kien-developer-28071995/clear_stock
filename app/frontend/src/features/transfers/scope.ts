/** Optional scope for creating draft transfers (shopify.app.toml `optional_scopes`). */
export const TRANSFER_SCOPE = 'write_inventory_transfers';

/**
 * Asks the merchant for the transfer permission (App Bridge dialog) unless it is already
 * granted. Resolves true when the app may create transfers.
 */
export async function ensureTransferScope(): Promise<boolean> {
    const current = await shopify.scopes.query();
    if (current.granted.includes(TRANSFER_SCOPE)) return true;
    const response = await shopify.scopes.request([TRANSFER_SCOPE]);
    return response.result === 'granted-all';
}
