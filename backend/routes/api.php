<?php

use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BundleController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataHealthController;
use App\Http\Controllers\Api\ForecastAccuracyController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\GrowthScenarioController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ManualOrderController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrderingController;
use App\Http\Controllers\Api\ProductExtensionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchasePlanController;
use App\Http\Controllers\Api\SalesEventController;
use App\Http\Controllers\Api\SavedViewController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SetupGuideController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\StockHistoryController;
use App\Http\Controllers\Api\StockInsightController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierEmailController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\VariantController;
use App\Http\Controllers\Api\VendorSupplierController;
use App\Http\Controllers\Api\WebVitalController;
use Illuminate\Support\Facades\Route;

// `usage:<feature>` counts uses of features that store nothing (App\Support\FeatureUsage).
// All API routes are called by the embedded app with an App Bridge session token.
// Ids in URLs are resolved through repositories scoped to the authenticated shop
// (no implicit route-model binding: it would run before the shop is known).
Route::middleware('shopify.session')->group(function () {
    Route::get('/shop', [ShopController::class, 'show']);

    // Errors from the embedded app, reported to Slack like server errors.
    Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:20,1');
    // Web vitals measured in the admin (App Bridge), for the owner's reports.
    Route::post('/web-vitals', [WebVitalController::class, 'store'])->middleware('throttle:30,1');

    Route::get('/sync', [SyncController::class, 'show']);
    Route::post('/sync', [SyncController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/onboarding', [OnboardingController::class, 'show']);
    Route::post('/onboarding', [OnboardingController::class, 'store']);

    Route::get('/dashboard', DashboardController::class);

    Route::get('/setup-guide', [SetupGuideController::class, 'show']);
    Route::post('/setup-guide/events', [SetupGuideController::class, 'event']);
    Route::post('/setup-guide/skip', [SetupGuideController::class, 'skip']);
    Route::post('/setup-guide/dismiss', [SetupGuideController::class, 'dismiss']);
    Route::post('/setup-guide/tips', [SetupGuideController::class, 'dismissTip']);

    Route::get('/forecasts', [ForecastController::class, 'index']);
    Route::get('/forecasts/export', [ForecastController::class, 'export'])->middleware('feature:product_export')->middleware('usage:product_export');
    Route::get('/locations', [ForecastController::class, 'locations']);
    Route::get('/facets', [ForecastController::class, 'facets']);
    Route::get('/forecasts/{variant}', [ForecastController::class, 'show'])->whereNumber('variant');
    Route::put('/forecasts/{variant}/overrides', [ForecastController::class, 'updateOverrides'])->whereNumber('variant');
    Route::put('/forecasts/{variant}/location-minimums', [ForecastController::class, 'updateLocationMinimums'])->whereNumber('variant');
    // What was changed on a product; "not now" for reorder suggestions.
    Route::get('/forecasts/{variant}/changes', [ForecastController::class, 'changes'])->whereNumber('variant')->middleware(['feature:change_log', 'usage:change_log']);
    Route::post('/snooze', [ForecastController::class, 'snooze'])->middleware('throttle:30,1')->middleware('feature:snooze');

    // Saved product list views; clearance list and broken size runs (Insights).
    Route::get('/views', [SavedViewController::class, 'index'])->middleware('feature:saved_views');
    Route::post('/views', [SavedViewController::class, 'store'])->middleware('throttle:30,1')->middleware('feature:saved_views');
    Route::delete('/views/{view}', [SavedViewController::class, 'destroy'])->whereNumber('view')->middleware('feature:saved_views');
    Route::get('/clearance', [StockInsightController::class, 'clearance'])->middleware('feature:clearance')->middleware('usage:clearance');
    Route::get('/clearance/export', [StockInsightController::class, 'clearanceExport'])->middleware('feature:clearance')->middleware('usage:clearance_export');
    Route::get('/size-runs', [StockInsightController::class, 'sizeRuns'])->middleware('feature:size_runs')->middleware('usage:size_runs');

    Route::get('/variants', [VariantController::class, 'index']);
    Route::put('/variants/settings', [VariantController::class, 'bulkUpdateSettings']);
    Route::put('/variants/{variant}/settings', [VariantController::class, 'updateSettings'])->whereNumber('variant');

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
    // Locations whose stock is not for sale (returns, damaged goods): left out of the forecast's stock.
    Route::get('/settings/locations', [SettingsController::class, 'locations'])->middleware('feature:location_exclusions');
    Route::put('/settings/locations', [SettingsController::class, 'updateLocations'])->middleware('feature:location_exclusions');

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/from-vendors', [VendorSupplierController::class, 'show'])->middleware('feature:vendor_suppliers');
    Route::post('/suppliers/from-vendors', [VendorSupplierController::class, 'store'])->middleware('throttle:10,1')->middleware('feature:vendor_suppliers');
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier');
    Route::get('/suppliers/{supplier}/email', [SupplierEmailController::class, 'show'])->whereNumber('supplier');
    Route::post('/suppliers/{supplier}/email', [SupplierEmailController::class, 'store'])->whereNumber('supplier')->middleware('throttle:20,1');

    Route::get('/billing', [BillingController::class, 'show']);
    Route::get('/billing/impact', [BillingController::class, 'impact']);
    Route::post('/billing', [BillingController::class, 'store'])->middleware('throttle:10,1');

    // What-if: sales +/- X% -> what to order (nothing saved).
    Route::get('/what-if', [GrowthScenarioController::class, 'show'])->middleware('usage:what_if');
    Route::get('/what-if/export', [GrowthScenarioController::class, 'export'])->middleware('usage:what_if_export');

    // Purchase plan: orders and spend week by week at the current sales rates (nothing saved).
    Route::get('/purchase-plan', [PurchasePlanController::class, 'show'])->middleware('usage:purchase_plan');
    Route::get('/purchase-plan/export', [PurchasePlanController::class, 'export'])->middleware('usage:purchase_plan_export');

    // Monthly purchasing budget: what to reorder first when cash is short (Starter).
    Route::get('/budget', [BudgetController::class, 'show'])->middleware('usage:budget');
    Route::put('/budget', [BudgetController::class, 'update']);

    // Unit costs entered in the app (win over Shopify's cost in money figures).
    Route::get('/costs', [CostController::class, 'index'])->middleware('feature:costs');
    Route::put('/costs', [CostController::class, 'update'])->middleware('throttle:30,1')->middleware('feature:costs');
    Route::post('/costs/import', [CostController::class, 'import'])->middleware('throttle:10,1')->middleware('feature:costs');

    // Orders placed outside Shopify ("mark as ordered"): counted as on the way.
    Route::get('/manual-orders', [ManualOrderController::class, 'index'])->middleware('feature:manual_orders');
    Route::post('/manual-orders', [ManualOrderController::class, 'store'])->middleware('throttle:30,1')->middleware('feature:manual_orders');
    Route::patch('/manual-orders/{order}', [ManualOrderController::class, 'update'])->whereNumber('order')->middleware('feature:manual_orders');

    // Sales events (promotions, Black Friday): known changes in sales on set days.
    Route::get('/sales-events', [SalesEventController::class, 'index']);
    Route::post('/sales-events', [SalesEventController::class, 'store'])->middleware('throttle:30,1');
    Route::put('/sales-events/{event}', [SalesEventController::class, 'update'])->whereNumber('event');
    Route::delete('/sales-events/{event}', [SalesEventController::class, 'destroy'])->whereNumber('event');

    // Forecast accuracy: past forecasts next to what really sold.
    Route::get('/accuracy', ForecastAccuracyController::class)->middleware('usage:accuracy');

    // Inventory units and value at cost, day by day; product data problems.
    Route::get('/stock-history', StockHistoryController::class)->middleware('feature:stock_history')->middleware('usage:stock_history');
    Route::get('/data-health', DataHealthController::class)->middleware('feature:data_health')->middleware('usage:data_health');

    Route::get('/purchase-orders/export', [PurchaseOrderController::class, 'export'])->middleware('usage:purchase_order_export');
    // Shopify's own open purchase orders (optional scope), and a product's other suppliers.
    Route::get('/shopify-purchase-orders', [OrderingController::class, 'shopifyPurchaseOrders'])->middleware('throttle:30,1');
    Route::get('/variants/{variant}/suppliers', [OrderingController::class, 'suppliers'])->whereNumber('variant')->middleware('feature:alternate_suppliers');
    Route::put('/variants/{variant}/suppliers', [OrderingController::class, 'updateSuppliers'])->whereNumber('variant')->middleware('feature:alternate_suppliers');
    Route::post('/variants/{variant}/suppliers/{supplier}/main', [OrderingController::class, 'makeMainSupplier'])->whereNumber(['variant', 'supplier'])->middleware('feature:alternate_suppliers');

    // Purchase order CSVs (Stocky and others) -> suppliers, product assignments, lead times.
    Route::post('/imports/purchase-orders/preview', [ImportController::class, 'preview'])->middleware('throttle:30,1')->middleware('feature:supplier_import');
    Route::post('/imports/purchase-orders/apply', [ImportController::class, 'apply'])->middleware('throttle:10,1')->middleware('feature:supplier_import');

    // Shopify admin extensions: forecast block on the product page, bulk settings on the product list.
    Route::get('/extension/products/{product}', [ProductExtensionController::class, 'show'])->whereNumber('product');
    Route::post('/extension/product-settings', [ProductExtensionController::class, 'updateSettings'])->middleware('throttle:30,1');
    Route::get('/extension/variants/{variant}', [ProductExtensionController::class, 'showVariant'])->whereNumber('variant');
    Route::post('/extension/manual-orders', [ProductExtensionController::class, 'markOrdered'])->middleware('throttle:30,1')->middleware('feature:manual_orders');

    // Growth: move stock between locations before ordering (draft transfers in Shopify).
    Route::get('/transfers', [TransferController::class, 'index'])->middleware('usage:transfers');
    Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/bundles', [BundleController::class, 'index'])->middleware('feature:bundles');
    Route::post('/bundles', [BundleController::class, 'store'])->middleware('feature:bundles');
    Route::delete('/bundles/{variant}', [BundleController::class, 'destroy'])->whereNumber('variant')->middleware('feature:bundles');
});
