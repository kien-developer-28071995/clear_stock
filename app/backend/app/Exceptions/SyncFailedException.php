<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sync run cannot continue. `code` and `params` are stored on the shop
 * (shops.sync_error) and translated by the app; the message is for logs.
 */
class SyncFailedException extends RuntimeException
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $params = [],
        string $logMessage = '',
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $errorCode);
    }
}
