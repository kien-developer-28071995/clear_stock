<?php

namespace App\Monitoring;

use Illuminate\Support\Str;
use Throwable;

/**
 * One log record turned into what the Slack message shows. Only whitelisted context
 * is kept, secrets are masked, and nothing about customers is ever included.
 */
final readonly class ErrorAlert
{
    /** Context keys worth showing (ids, domains, topics); everything else is dropped. */
    private const CONTEXT_KEYS = [
        'shop', 'run', 'run_id', 'job', 'topic', 'webhook_id', 'query', 'status', 'error_code',
        'variant_id', 'attempts', 'source', 'url', 'component', 'where', 'command', 'request',
    ];

    /** @param array<string, string> $context */
    public function __construct(
        public string $level,
        public string $message,
        public ?string $exceptionClass,
        public ?string $location,
        public array $trace,
        public array $context,
        public string $fingerprint,
    ) {}

    /** @param array<string, mixed> $context log context (may hold 'exception') + Laravel Context data */
    public static function fromLog(string $level, string $message, array $context): self
    {
        $e = ($context['exception'] ?? null) instanceof Throwable ? $context['exception'] : null;

        $kept = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if (isset($context[$key]) && (is_scalar($context[$key]) || $context[$key] instanceof \Stringable)) {
                $kept[$key] = Str::limit(self::mask((string) $context[$key]), 200);
            }
        }
        if (isset($context['error']) && is_scalar($context['error'])) {
            $kept['error'] = Str::limit(self::mask((string) $context['error']), 300);
        }

        $location = $e ? self::relative($e->getFile()).':'.$e->getLine() : null;
        // The log message, plus the exception's own message when it adds something.
        $text = self::mask($e && ! str_contains($message, $e->getMessage()) ? "{$message}\n{$e->getMessage()}" : $message);

        return new self(
            level: strtolower($level),
            message: Str::limit($text, 700),
            exceptionClass: $e ? get_class($e) : null,
            location: $location,
            trace: $e ? self::trace($e) : [],
            context: $kept,
            // Same error = same class and place (exceptions) or same message shape (plain logs).
            fingerprint: sha1($e ? get_class($e).'|'.$location : strtolower($level).'|'.preg_replace('/\d+/', '#', $message)),
        );
    }

    /** Access tokens, bearer tokens and secrets never leave the server. */
    public static function mask(string $text): string
    {
        return preg_replace(
            ['/\b(shpat|shpua|shpca|shpss|shppa)_[A-Za-z0-9]+/', '/Bearer\s+[A-Za-z0-9\-_.]+/i', '/eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', '/(secret|password|token)=([^&\s]+)/i'],
            ['$1_***', 'Bearer ***', '***jwt***', '$1=***'],
            $text,
        );
    }

    /** @return array<int, string> app frames first, vendor frames after */
    private static function trace(Throwable $e): array
    {
        $frames = array_map(
            fn ($f) => isset($f['file']) ? self::relative($f['file']).':'.($f['line'] ?? '?').' '.($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '') : null,
            $e->getTrace(),
        );
        $frames = array_values(array_filter($frames));
        $app = array_values(array_filter($frames, fn ($f) => ! str_starts_with($f, 'vendor/')));

        return array_slice([...$app, ...array_diff($frames, $app)], 0, (int) config('monitoring.trace_lines', 8));
    }

    private static function relative(string $path): string
    {
        return ltrim(Str::after($path, base_path()), '/');
    }
}
