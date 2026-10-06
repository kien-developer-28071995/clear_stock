<?php

namespace App\Exceptions;

use RuntimeException;

/** The App Bridge session token (ID token) is missing, expired or forged. */
class InvalidSessionTokenException extends RuntimeException {}
