<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Support\CacheKeys;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Step 2 of rotating APP_KEY (docs/DEPLOY.md "Đổi APP_KEY"): with the new APP_KEY and the old one
 * in APP_PREVIOUS_KEYS, rewrite every encrypted column with the new key. Afterwards
 * APP_PREVIOUS_KEYS can be emptied. Safe to run more than once.
 */
class ReencryptSecrets extends Command
{
    protected $signature = 'app:reencrypt-secrets {--dry-run : Only count what would change}';

    protected $description = 'Re-encrypt stored Shopify tokens with the current APP_KEY (after rotating it)';

    /** Every column stored with the "encrypted" cast. */
    private const COLUMNS = ['access_token', 'refresh_token'];

    public function handle(): int
    {
        $current = $this->currentKeyOnly();
        $counts = ['current' => 0, 'reencrypted' => 0, 'unreadable' => 0];

        Shop::query()->orderBy('id')->each(function (Shop $shop) use ($current, &$counts) {
            $updates = [];
            foreach (self::COLUMNS as $column) {
                $raw = $shop->getRawOriginal($column);
                if ($raw === null || $raw === '') {
                    continue;
                }
                if ($this->opens($current, $raw)) {
                    $counts['current']++;

                    continue;
                }
                try {
                    // Tries APP_KEY, then every key in APP_PREVIOUS_KEYS.
                    $updates[$column] = $current->encryptString(Crypt::decryptString($raw));
                    $counts['reencrypted']++;
                } catch (DecryptException) {
                    $counts['unreadable']++;
                    $this->error("{$shop->domain}: {$column} cannot be decrypted with APP_KEY or APP_PREVIOUS_KEYS");
                }
            }

            if ($updates !== [] && ! $this->option('dry-run')) {
                // Raw ciphertext on purpose: a model save would see "same value" and skip it.
                Shop::query()->whereKey($shop->id)->update($updates);
                Cache::forget(CacheKeys::shopById($shop->id));
                Cache::forget(CacheKeys::shopByDomain($shop->domain));
            }
        });

        $verb = $this->option('dry-run') ? 'would be re-encrypted' : 're-encrypted';
        $this->info("{$counts['reencrypted']} value(s) {$verb}, {$counts['current']} already on the current key, {$counts['unreadable']} unreadable.");
        if ($counts['unreadable'] > 0) {
            $this->warn('Keep APP_PREVIOUS_KEYS until every value is readable (or the shop reinstalls: a new token replaces it).');

            return self::FAILURE;
        }
        if (! $this->option('dry-run') && filled(config('app.previous_keys'))) {
            $this->line('Every token uses the current key: APP_PREVIOUS_KEYS can now be emptied.');
        }

        return self::SUCCESS;
    }

    private function currentKeyOnly(): Encrypter
    {
        $key = (string) config('app.key');
        $key = Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key;

        return new Encrypter($key, config('app.cipher'));
    }

    private function opens(Encrypter $encrypter, string $payload): bool
    {
        try {
            $encrypter->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
