<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/** The only way to get an account: there is no sign-up page. Running it again resets the password. */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:user {email} {--password= : Leave out to be asked (not echoed)}';

    protected $description = 'Create the owner account, or reset its password';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (at least 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(['email' => $this->argument('email')], ['name' => 'Owner', 'password' => Hash::make($password)]);
        $this->info(($user->wasRecentlyCreated ? 'Created ' : 'Password reset for ').$user->email);

        return self::SUCCESS;
    }
}
