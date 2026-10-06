<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * JSON error bodies for the API: {code, params} and, for validation, per-field
 * {code, params}. Codes are snake_case; the frontend owns every message.
 */
final class ApiErrorResponse
{
    /** Rules whose parameters are worth passing to the message. */
    private const RULE_PARAMS = [
        'min' => ['value'], 'max' => ['value'], 'size' => ['value'],
        'between' => ['min', 'max'], 'digits' => ['value'], 'gt' => ['value'], 'gte' => ['value'], 'lt' => ['value'], 'lte' => ['value'],
    ];

    /** @param array<string, mixed> $params */
    public static function make(string $code, int $status, array $params = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'params' => (object) $params], $status, $headers);
    }

    public static function forValidation(ValidationException $e): JsonResponse
    {
        $failed = $e->validator->failed();
        $errors = [];

        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $rules = array_values(array_map(null, array_keys($failed[$field] ?? []), array_values($failed[$field] ?? [])));
            foreach ($messages as $i => $message) {
                [$rule, $args] = $rules[$i] ?? [null, []];
                $errors[$field][] = self::fieldError($rule, $args ?? [], $message);
            }
        }

        return new JsonResponse(['code' => 'validation_failed', 'params' => (object) [], 'errors' => $errors], $e->status);
    }

    public static function forHttp(HttpExceptionInterface $e): JsonResponse
    {
        $code = match ($e->getStatusCode()) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'page_expired',
            429 => 'too_many_requests',
            503 => 'unavailable',
            default => 'http_error',
        };

        return self::make($code, $e->getStatusCode(), [], $e->getHeaders());
    }

    public static function forServerError(Throwable $e): JsonResponse
    {
        $response = self::make('server_error', 500);
        if (config('app.debug')) {
            $response->setData($response->getData(true) + ['debug' => get_class($e).': '.$e->getMessage()]);
        }

        return $response;
    }

    /**
     * Built-in rules ("Max") become their snake_case name ("max"). Rule objects and
     * closures, and errors added with ValidationException::withMessages(), write the
     * code themselves as the message ($fail('invalid_product')).
     *
     * @return array{code: string, params: object}
     */
    private static function fieldError(?string $rule, array $args, string $message): array
    {
        if ($rule === null || str_contains($rule, '\\')) {
            return ['code' => $message, 'params' => (object) []];
        }

        $code = Str::snake($rule);
        $names = self::RULE_PARAMS[$code] ?? [];
        $params = [];
        foreach ($names as $i => $name) {
            if (isset($args[$i])) {
                $params[$name] = is_numeric($args[$i]) ? $args[$i] + 0 : $args[$i];
            }
        }

        return ['code' => $code, 'params' => (object) $params];
    }
}
