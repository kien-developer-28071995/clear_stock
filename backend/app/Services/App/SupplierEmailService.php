<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Exceptions\ApiException;
use App\Mail\SupplierOrderMail;
use App\Models\Forecast;
use App\Models\ManualOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierEmail;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\SupplierEmailRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\CacheKeys;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Emails a supplier a purchase order (Growth): the merchant reviews and sends it, or,
 * for suppliers with auto_email on, it goes out when their products are due, at most
 * once per `alerts.supplier_auto_interval_days`. Replies go to the merchant.
 */
class SupplierEmailService
{
    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly SupplierEmailRepositoryInterface $emails,
        private readonly VariantRepositoryInterface $variants,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly OnboardingService $onboarding,
        private readonly ManualOrderService $manualOrders,
    ) {}

    /**
     * What the email would contain: this supplier's products due now (or the given
     * products), with the suggested quantities. The merchant can edit before sending.
     *
     * @param  array<int, int>|null  $variantIds
     */
    public function draft(Shop $shop, Supplier $supplier, ?array $variantIds = null): array
    {
        $this->assertAllowed($shop);

        return [
            'to' => $supplier->email,
            'reply_to' => $this->replyTo($shop),
            'items' => $this->dueItems($shop, $supplier, $variantIds),
            'last_emailed_at' => $this->emails->lastSentAt($supplier)?->toIso8601String(),
        ];
    }

    /** @param array<int, array{variant_id: int, quantity: int}> $items */
    public function send(Shop $shop, Supplier $supplier, array $items, ?string $message, ?string $replyTo, string $trigger = SupplierEmail::TRIGGER_MANUAL): SupplierEmail
    {
        $this->assertAllowed($shop);
        if (! $supplier->email) {
            throw new ApiException('supplier_has_no_email', 422);
        }

        $variants = $this->variants->findMany($shop, array_column($items, 'variant_id'));
        $lines = [];
        foreach ($items as $item) {
            $v = $variants[$item['variant_id']] ?? null;
            if ($v !== null && $item['quantity'] > 0) {
                $lines[] = ['variant_id' => $v->id, 'sku' => $v->sku, 'name' => $v->displayName(), 'quantity' => (int) $item['quantity']];
            }
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'no_items']);
        }

        // A double click must not email the supplier twice.
        if ($trigger === SupplierEmail::TRIGGER_MANUAL && ! Cache::add(CacheKeys::supplierEmailLock($supplier->id), true, 60)) {
            throw new ApiException('supplier_email_too_soon', 429);
        }

        $replyTo = $replyTo ?: $this->replyTo($shop);
        Mail::to($supplier->email)->queue(new SupplierOrderMail($shop, $supplier->name, $lines, $message ? trim($message) : null, $replyTo));

        Log::info('Supplier purchase order emailed', ['shop' => $shop->domain, 'supplier' => $supplier->id, 'trigger' => $trigger, 'items' => count($lines)]);

        // What was emailed is ordered: count it as on the way so it is not suggested again.
        $this->manualOrders->record($shop, array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => $l['quantity']], $lines),
            reference: 'Email', source: ManualOrder::SOURCE_SUPPLIER_EMAIL);

        return $this->emails->log($shop, $supplier, $trigger, $supplier->email, $replyTo, $lines);
    }

    /** Automatic emails for suppliers that opted in. @return int emails sent */
    public function sendDue(Shop $shop, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        if (! $shop->isInstalled() || ! Entitlements::for($shop)->has(Feature::SupplierAutoEmail)) {
            return 0;
        }
        if ($now->setTimezone($shop->timezone)->hour < (int) config('alerts.send_hour')) {
            return 0;
        }
        if ($shop->forecasted_at === null || $shop->forecasted_at->lt($now->subHours((int) config('alerts.max_forecast_age_hours')))) {
            return 0; // don't order from stale numbers
        }
        $replyTo = $this->replyTo($shop);
        if ($replyTo === null) {
            return 0; // the supplier must be able to answer someone
        }

        $sent = 0;
        $since = $now->subDays((int) config('alerts.supplier_auto_interval_days'));
        foreach ($this->suppliers->allForShop($shop) as $supplier) {
            if (! $supplier->auto_email || ! $supplier->email) {
                continue;
            }
            // With order weekdays: send on those days only, at most once each day; else once a week.
            $days = $supplier->order_weekdays;
            $local = $now->setTimezone($shop->timezone);
            if ($days !== null && ! in_array($local->isoWeekday(), $days, true)) {
                continue;
            }
            $last = $this->emails->lastSentAt($supplier, SupplierEmail::TRIGGER_AUTO);
            if ($last !== null && $last->gt($days !== null ? $local->startOfDay() : $since)) {
                continue;
            }
            $items = $this->dueItems($shop, $supplier, null);
            if ($items === []) {
                continue;
            }
            $this->send($shop, $supplier, array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity']], $items), null, $replyTo, SupplierEmail::TRIGGER_AUTO);
            $sent++;
        }

        return $sent;
    }

    /** @return array<int, array{variant_id: int, name: string, sku: ?string, quantity: int}> */
    private function dueItems(Shop $shop, Supplier $supplier, ?array $variantIds): array
    {
        $today = CarbonImmutable::now($shop->timezone)->toDateString();

        return $this->forecasts->reorderList($shop, $today, $supplier->id, null, $variantIds)
            ->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'quantity' => $f->suggested_qty,
            ])->values()->all();
    }

    /** Where supplier replies go: the alert email, else the store contact email. */
    private function replyTo(Shop $shop): ?string
    {
        return $this->alerts->forShop($shop)?->email ?: $this->onboarding->contactEmail($shop);
    }

    private function assertAllowed(Shop $shop): void
    {
        Entitlements::for($shop)->require(Feature::SupplierEmails);
    }
}
