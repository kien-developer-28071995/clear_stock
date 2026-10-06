<?php

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function keyFor(string $bytes): string
{
    return 'base64:'.base64_encode($bytes);
}

/** Point the app at a new APP_KEY (and optional previous keys), like a redeploy with new env. */
function useKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    Model::encryptUsing(app('encrypter'));
}

it('rotates APP_KEY: old tokens stay readable via APP_PREVIOUS_KEYS, then move to the new key', function () {
    $old = keyFor(str_repeat('a', 32));
    $new = keyFor(str_repeat('b', 32));
    useKeys($old);
    $shop = Shop::factory()->create(['access_token' => 'shpat_old', 'refresh_token' => 'shprt_old']);

    // New key, old one kept as previous: still readable.
    useKeys($new, [$old]);
    expect(Shop::find($shop->id)->access_token)->toBe('shpat_old');

    $this->artisan('app:reencrypt-secrets --dry-run')->expectsOutputToContain('2 value(s) would be re-encrypted')->assertSuccessful();
    $this->artisan('app:reencrypt-secrets')->expectsOutputToContain('2 value(s) re-encrypted')->assertSuccessful();

    // Previous key gone: tokens open with the new key alone.
    useKeys($new);
    $fresh = Shop::find($shop->id);
    expect($fresh->access_token)->toBe('shpat_old')->and($fresh->refresh_token)->toBe('shprt_old');
    $this->artisan('app:reencrypt-secrets')->expectsOutputToContain('0 value(s) re-encrypted, 2 already on the current key')->assertSuccessful();
});

it('fails and names the shop when a token matches no key', function () {
    $shop = Shop::factory()->create(['domain' => 'lost.myshopify.com']);
    DB::table('shops')->where('id', $shop->id)->update(['access_token' => (new Encrypter(str_repeat('z', 32), 'AES-256-CBC'))->encryptString('x')]);

    $this->artisan('app:reencrypt-secrets')->expectsOutputToContain('lost.myshopify.com: access_token cannot be decrypted')->assertFailed();
});
