<?php

namespace App\Enums;

/** Webhook topics the app subscribes to (see shopify.app.toml). */
enum WebhookTopic: string
{
    case AppUninstalled = 'app/uninstalled';
    case AppScopesUpdate = 'app/scopes_update';
    case CustomersDataRequest = 'customers/data_request';
    case CustomersRedact = 'customers/redact';
    case ShopRedact = 'shop/redact';
    case BulkOperationsFinish = 'bulk_operations/finish';
}
