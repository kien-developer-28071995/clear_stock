<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Errors from the embedded app (JavaScript errors, React crashes), logged like server errors so they reach Slack. */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:500'],
            'component' => ['nullable', 'string', 'max:2000'],
        ]);

        Log::error('Frontend: '.Str::limit($data['message'], 500), [
            'source' => 'frontend',
            // App route only (no query string); the stack carries bundle file names, not data.
            'url' => Str::before((string) ($data['url'] ?? ''), '?'),
            'error' => Str::limit(trim((string) ($data['stack'] ?? '')), 1200),
            'component' => Str::limit(trim((string) ($data['component'] ?? '')), 300),
        ]);

        return response()->noContent();
    }
}
