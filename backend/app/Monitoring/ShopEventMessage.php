<?php

namespace App\Monitoring;

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Models\Shop;

/**
 * Slack messages for business events: install, uninstall, upgrade, downgrade, billing
 * interval change. Internal channel, English; shop domain/name only (no customer data).
 */
final class ShopEventMessage
{
    private const RANK = ['free' => 0, 'starter' => 1, 'growth' => 2];

    public static function installed(Shop $shop, bool $reinstall, int $activeInstalls): array
    {
        return self::build($reinstall ? ':recycle: Reinstall' : ':tada: New install', $shop, [
            'Plan' => self::planLabel($shop->plan, null),
            'Currency / timezone' => ($shop->currency ?? '?').' · '.$shop->timezone,
            'Active installs' => (string) $activeInstalls,
        ]);
    }

    public static function uninstalled(Shop $shop, ?Plan $plan, int $activeInstalls): array
    {
        return self::build(':wave: Uninstall', $shop, array_filter([
            'Plan at uninstall' => self::planLabel($plan, null),
            'Installed for' => $shop->installed_at ? (int) $shop->installed_at->diffInDays(now()).' days' : null,
            'Active installs' => (string) $activeInstalls,
        ]));
    }

    public static function planChanged(Shop $shop, ?Plan $from, ?PlanInterval $fromInterval, Plan $to, ?PlanInterval $toInterval): array
    {
        $rankFrom = self::RANK[$from?->value ?? 'free'];
        $rankTo = self::RANK[$to->value];
        $title = match (true) {
            $rankTo > $rankFrom => ':arrow_up: Upgrade',
            $rankTo < $rankFrom => ':arrow_down: Downgrade',
            default => ':repeat: Billing interval changed',
        };
        $mrr = self::monthly($to, $toInterval) - self::monthly($from, $fromInterval);

        return self::build($title, $shop, [
            'From' => self::planLabel($from, $fromInterval),
            'To' => self::planLabel($to, $toInterval),
            'MRR change' => ($mrr >= 0 ? '+' : '-').'$'.number_format(abs($mrr), 2),
        ]);
    }

    /** @param array<string, string> $fields */
    private static function build(string $title, Shop $shop, array $fields): array
    {
        $header = '['.config('monitoring.environment').'] '.$title;
        $shopText = ($shop->name ? "*{$shop->name}*\n" : '').$shop->domain;

        return [
            'text' => "{$header}: {$shop->domain}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $header, 'emoji' => true]],
                ['type' => 'section', 'fields' => array_map(
                    fn ($label, $value) => ['type' => 'mrkdwn', 'text' => "*{$label}*\n{$value}"],
                    ['Shop', ...array_keys($fields)],
                    [$shopText, ...array_values($fields)],
                )],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => now()->utc()->toDateTimeString().' UTC']]],
            ],
        ];
    }

    private static function planLabel(?Plan $plan, ?PlanInterval $interval): string
    {
        $plan ??= Plan::Free;
        $price = $interval ? config("billing.plans.{$plan->value}.prices.{$interval->value}") : null;

        return config("billing.plans.{$plan->value}.name")
            .($interval ? " ({$interval->value})" : '')
            .($price ? ' · $'.number_format((float) $price, 2).($interval === PlanInterval::Annual ? '/yr' : '/mo') : '');
    }

    /** Monthly recurring revenue of a plan (annual price / 12). */
    private static function monthly(?Plan $plan, ?PlanInterval $interval): float
    {
        if ($plan === null || $interval === null) {
            return 0.0;
        }
        $price = (float) config("billing.plans.{$plan->value}.prices.{$interval->value}", 0);

        return $interval === PlanInterval::Annual ? $price / 12 : $price;
    }
}
