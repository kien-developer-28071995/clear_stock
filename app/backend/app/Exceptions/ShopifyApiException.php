<?php

namespace App\Exceptions;

use RuntimeException;

/** Shopify returned an error or could not be reached. `retryable` tells jobs whether to back off and retry. */
class ShopifyApiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
