<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads purchase order CSV exports (Stocky, other inventory apps, spreadsheets) and
 * guesses which column holds what. Column names are not standardised (Stocky never
 * documented them), so detection is by header aliases, dates are checked by value,
 * and the merchant can correct the mapping in the app.
 */
class PurchaseOrderCsv
{
    public const MAX_ROWS = 50_000;

    /** Fields the importer understands. supplier + one product field are required. */
    public const FIELDS = ['po_number', 'supplier', 'sku', 'variant_id', 'product', 'variant', 'ordered_at', 'expected_at', 'received_at'];

    public const DATE_FIELDS = ['ordered_at', 'expected_at', 'received_at'];

    /** Normalised header (lowercase, letters and digits only) => field. First match wins. */
    private const ALIASES = [
        'po_number' => ['ponumber', 'purchaseordernumber', 'purchaseorder', 'purchaseorderid', 'poid', 'po', 'ordernumber', 'reference', 'number', 'id'],
        'supplier' => ['supplier', 'suppliername', 'vendor', 'vendorname', 'supplierscompany', 'company'],
        'sku' => ['sku', 'variantsku', 'productsku', 'itemsku'],
        'variant_id' => ['variantid', 'shopifyvariantid', 'productvariantid', 'shopifyid'],
        'product' => ['product', 'producttitle', 'productname', 'title', 'name', 'item', 'itemname', 'description'],
        'variant' => ['variant', 'varianttitle', 'variantname', 'option', 'options'],
        'ordered_at' => ['orderedat', 'ordereddate', 'orderdate', 'dateordered', 'orderedon', 'ordered', 'createdat', 'datecreated', 'created', 'sentat', 'datesent', 'date'],
        'expected_at' => ['expectedat', 'expectedon', 'expecteddate', 'expectedarrival', 'expecteddelivery', 'eta', 'duedate', 'expected'],
        'received_at' => ['receivedat', 'receivedon', 'receiveddate', 'datereceived', 'arrivedat', 'arrivaldate', 'completedat', 'datecompleted', 'received'],
    ];

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{columns: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function read(array $files): array
    {
        $columns = [];
        $rows = [];

        foreach ($files as $file) {
            $handle = @fopen($file->getRealPath(), 'r');
            $first = $handle ? fgets($handle) : false;
            if ($first === false) {
                throw ValidationException::withMessages(['files' => 'file_unreadable']);
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = $this->delimiter($first);
            $header = array_map(fn ($h) => trim((string) $h), str_getcsv($first, $delimiter));
            if (count(array_filter($header)) < 2) {
                throw ValidationException::withMessages(['files' => 'file_unreadable']);
            }
            $columns = array_values(array_unique([...$columns, ...array_filter($header)]));

            while (($values = fgetcsv($handle, null, $delimiter)) !== false) {
                if ($values === [null] || count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $name) {
                    if ($name !== '') {
                        $row[$name] = trim((string) ($values[$i] ?? ''));
                    }
                }
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['files' => 'too_many_rows']);
                }
            }
            fclose($handle);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['files' => 'no_rows']);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Best guess of field => column. Date fields only take columns whose values look
     * like dates (a "Received" column can be a quantity).
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, ?string>
     */
    public function detect(array $columns, array $rows): array
    {
        $normalised = [];
        foreach ($columns as $column) {
            $normalised[$column] = Str::of($column)->lower()->ascii()->replaceMatches('/[^a-z0-9]/', '')->toString();
        }

        $mapping = array_fill_keys(self::FIELDS, null);
        $taken = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $column = array_search($alias, $normalised, true);
                if ($column === false || isset($taken[$column])) {
                    continue;
                }
                if (in_array($field, self::DATE_FIELDS, true) && ! $this->looksLikeDates($column, $rows)) {
                    continue;
                }
                $mapping[$field] = $column;
                $taken[$column] = true;
                break;
            }
        }

        return $mapping;
    }

    /** Date (Y-m-d) from a cell, or null when empty or not a date. */
    public function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || is_numeric($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function looksLikeDates(string $column, array $rows): bool
    {
        $values = array_slice(array_filter(array_map(fn ($r) => $r[$column] ?? '', $rows), fn ($v) => $v !== ''), 0, 20);
        if ($values === []) {
            return true; // empty column (e.g. nothing received yet): harmless
        }
        $dates = count(array_filter($values, fn ($v) => $this->date($v) !== null));

        return $dates >= count($values) / 2;
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return array_key_first($counts);
    }
}
