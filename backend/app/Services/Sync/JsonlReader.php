<?php

namespace App\Services\Sync;

use Generator;
use RuntimeException;

/** Streams a bulk operation JSONL file line by line (constant memory). */
final class JsonlReader
{
    /** @return Generator<int, array> */
    public static function read(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
