<?php

namespace Tests\Feature;

use App\Models\User;
use App\Reports\LedgerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AppSchema;
use Tests\TestCase;

/** Odd addresses and odd data from the app's database never break a report, and never run as markup. */
class HostileInputTest extends TestCase
{
    use AppSchema, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppSchema();
        $this->travelTo('2026-10-01 08:00:00');
    }

    public function test_reports_survive_any_query_and_escape_what_shops_call_themselves(): void
    {
        $evil = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $id = $this->appShop(['domain' => 'evil.myshopify.com', 'name' => $evil, 'plan' => 'starter', 'plan_interval' => 'monthly', 'installed_at' => '2026-09-22 10:00:00',
            'sync_status' => 'failed', 'sync_error' => '{not json', 'onboarded_at' => '2026-09-22 10:05:00']);
        $this->appShop(['domain' => 'odd.myshopify.com', 'name' => null, 'plan' => 'platinum-that-does-not-exist', 'installed_at' => '2026-09-25 10:00:00', 'sync_error' => json_encode(['code' => $evil])]);
        app(LedgerSync::class)->run();
        $owner = User::factory()->create();

        $queries = [
            '', '?status=nonsense&plan[]=x&feature=<b>&q='.urlencode($evil).'&page=-4', '?page=999999999&status[]=a&q[]=b', '?q='.str_repeat('%25_', 2000),
            '?plan='.urlencode("' OR 1=1 --").'&feature='.urlencode('../../etc/passwd').'&sort=drop',
        ];
        foreach (['/', '/shops', '/shops/export', '/features', '/health', "/shops/{$id}", '/shops/999999999'] as $path) {
            foreach ($queries as $query) {
                $response = $this->actingAs($owner)->get($path.$query);
                $status = $response->getStatusCode();
                $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
                $this->assertLessThan(500, $status, "{$path}{$query} -> {$status}");
                if ($path === '/shops/export') {
                    // A file to open in a spreadsheet, not a page: names are text there.
                    $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'), 'the export is a CSV file');

                    continue;
                }
                $this->assertStringNotContainsString('<script>alert(1)</script>', $body, "{$path}{$query} shows a shop name as markup");
                $this->assertStringNotContainsString('<img src=x onerror', $body, "{$path}{$query} shows markup from the address or a shop name");
            }
        }
    }

    public function test_a_guest_reaches_nothing_but_the_login_page(): void
    {
        foreach (['/', '/shops', '/shops/export', '/shops/1', '/features', '/health'] as $url) {
            $this->get($url.'?q=<script>')->assertRedirect('/login');
        }
        $this->post('/sync')->assertRedirect('/login');
        $this->post('/login', ['email' => ['array'], 'password' => str_repeat('x', 100000)])->assertSessionHasErrors();
        $this->post('/login', ['email' => "a@b.c' OR 1=1 --", 'password' => 'x'])->assertSessionHasErrors();
        $this->assertGuest();
    }
}
