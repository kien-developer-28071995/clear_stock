<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The shop's offline token can no longer be refreshed. A new token exchange is
 * needed, which happens the next time the merchant opens the app.
 */
class ShopifyReauthorizeException extends RuntimeException {}
