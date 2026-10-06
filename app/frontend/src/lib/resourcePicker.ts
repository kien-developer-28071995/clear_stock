export interface PickedVariant {
    gid: string;
    name: string;
}

/** Shopify's own product picker (App Bridge). Returns an empty list when cancelled. */
export async function pickVariants(options: { multiple?: boolean; selected?: string[] } = {}): Promise<PickedVariant[]> {
    const selection = await shopify.resourcePicker({
        type: 'variant',
        action: 'select',
        multiple: options.multiple ?? false,
        selectionIds: (options.selected ?? []).map((id) => ({ id })),
    });

    return (selection ?? []).map((v) => ({ gid: v.id, name: v.displayName ?? v.title }));
}
