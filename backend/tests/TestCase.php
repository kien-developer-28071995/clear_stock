<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.api_key' => TEST_API_KEY,
            'shopify.api_secret' => TEST_API_SECRET,
            'shopify.scopes' => 'read_products,read_inventory,read_locations,read_orders,read_all_orders',
        ]);
    }
}
