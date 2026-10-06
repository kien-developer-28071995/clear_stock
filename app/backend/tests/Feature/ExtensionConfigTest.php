<?php

/**
 * Rules Shopify applies to extension config when a version is created (`shopify app deploy`).
 * Both were learned from a refused deploy (2026-10-06); nothing local checks them otherwise.
 */
$files = fn () => glob(base_path('../../extensions/*/shopify.extension.toml')) ?: [];

it('gives every admin UI extension exactly one target', function () use ($files) {
    $checked = 0;
    foreach ($files() as $file) {
        // One block per [[extensions]] entry.
        foreach (array_slice(preg_split('/^\[\[extensions\]\]\s*$/m', file_get_contents($file)), 1) as $extension) {
            if (! str_contains($extension, 'type = "ui_extension"')) {
                continue;
            }
            $checked++;
            expect(preg_match_all('/^\s*\[\[extensions\.targeting\]\]/m', $extension))->toBe(1, basename(dirname($file)).': one target per extension');
        }
    }
    expect($checked)->toBeGreaterThan(0);
})->skip(fn () => $files() === [], 'the extensions are outside the backend image');

it('keeps extension handles and uids unique and descriptions within 140 characters', function () use ($files) {
    $handles = $uids = [];
    foreach ($files() as $file) {
        $toml = file_get_contents($file);
        preg_match_all('/^\s*handle\s*=\s*"([^"]+)"/m', $toml, $h);
        preg_match_all('/^\s*uid\s*=\s*"([^"]+)"/m', $toml, $u);
        array_push($handles, ...$h[1]);
        array_push($uids, ...$u[1]);
        preg_match_all('/^\s*description\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/m', $toml, $d);
        foreach ($d[1] as $description) {
            expect(mb_strlen(stripcslashes($description)))->toBeLessThanOrEqual(140, basename(dirname($file)).": \"{$description}\"");
        }
    }
    expect($handles)->toBe(array_values(array_unique($handles)))
        ->and($uids)->toBe(array_values(array_unique($uids)));
})->skip(fn () => $files() === [], 'the extensions are outside the backend image');
