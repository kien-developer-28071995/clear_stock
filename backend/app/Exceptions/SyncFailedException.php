<?php

namespace App\Exceptions;

use RuntimeException;

/** A sync run cannot continue. The message is shown to the merchant. */
class SyncFailedException extends RuntimeException {}
