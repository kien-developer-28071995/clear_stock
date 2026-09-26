<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An API error for the embedded app. The API never returns text: the body is a
 * snake_case `code` (plus `params`) that the frontend translates.
 */
class ApiException extends RuntimeException
{
    /** @var array<string, string> */
    public array $headers = [];

    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $params = [],
        array $headers = [],
    ) {
        parent::__construct($errorCode);
        $this->headers = $headers;
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public function render(): JsonResponse
    {
        return ApiErrorResponse::make($this->errorCode, $this->status, $this->params, $this->headers);
    }
}
