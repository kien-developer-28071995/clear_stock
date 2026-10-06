<?php

namespace App\Http\Requests;

use App\Services\Import\PurchaseOrderCsv;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Multipart: files[] (CSV) plus, as JSON strings, the column mapping and (on apply)
 * the lead times confirmed by the merchant.
 */
class PurchaseOrderImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:10240', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream'],
            'mapping' => ['nullable', 'json'],
            'suppliers' => ['nullable', 'json'],
            'replace_existing' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, ?string>|null */
    public function mapping(): ?array
    {
        $mapping = json_decode((string) $this->input('mapping'), true);

        return is_array($mapping) ? array_intersect_key($mapping, array_flip(PurchaseOrderCsv::FIELDS)) : null;
    }

    /** @return array<int, array{name: string, lead_time_days: ?int}> */
    public function leadTimes(): array
    {
        $suppliers = json_decode((string) $this->input('suppliers'), true);

        return collect(is_array($suppliers) ? $suppliers : [])
            ->filter(fn ($s) => is_array($s) && is_string($s['name'] ?? null))
            ->map(fn ($s) => [
                'name' => $s['name'],
                'lead_time_days' => is_numeric($s['lead_time_days'] ?? null) ? max(0, min(365, (int) $s['lead_time_days'])) : null,
            ])->values()->all();
    }
}
