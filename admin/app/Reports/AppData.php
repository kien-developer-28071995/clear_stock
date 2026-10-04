<?php

namespace App\Reports;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read access to the Clear Stock app's database. The reports run against whatever version of
 * the app is deployed, so every query first checks that its tables and columns exist.
 */
class AppData
{
    /** @var array<string, array<int, string>|false> */
    private array $columns = [];

    public function db(): Connection
    {
        return DB::connection('app');
    }

    public function table(string $table): Builder
    {
        return $this->db()->table($table);
    }

    /** @param array<int, string> $columns */
    public function has(string $table, array $columns = []): bool
    {
        $this->columns[$table] ??= Schema::connection('app')->hasTable($table)
            ? Schema::connection('app')->getColumnListing($table)
            : false;

        return $this->columns[$table] !== false && array_diff($columns, $this->columns[$table]) === [];
    }

    /** Installed shops: not uninstalled. */
    public function installedShops(): Builder
    {
        return $this->table('shops')->whereNull('uninstalled_at');
    }
}
