import '@shopify/ui-extensions';

//@ts-ignore
declare module './src/ProductForecastBlock.tsx' {
  const shopify: import('@shopify/ui-extensions/admin.product-details.block.render').Api;
  const globalThis: { shopify: typeof shopify };
}

//@ts-ignore
declare module './src/ProductForecastBlock.tsx' {
  const shopify: import('@shopify/ui-extensions/admin.product-variant-details.block.render').Api;
  const globalThis: { shopify: typeof shopify };
}
