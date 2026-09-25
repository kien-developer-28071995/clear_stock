<?php

namespace App\Enums;

enum SyncType: string
{
    /** First sync after install: 365 days of orders, full catalog. */
    case Initial = 'initial';
    /** Scheduled nightly run: recent orders window, changed variants only. */
    case Nightly = 'nightly';
    /** Started by the merchant from the UI. */
    case Manual = 'manual';
}
