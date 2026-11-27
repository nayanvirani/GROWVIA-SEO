<?php

namespace Tests\Unit;

use App\Domain\Backlink\BacklinkDiscovery;
use PHPUnit\Framework\TestCase;

class CompetitorOwnedTest extends TestCase
{
    public function test_detects_vendor_blogs_from_their_own_app_listing()
    {
        $this->assertTrue(BacklinkDiscovery::ownsListing('kachingappz.com', ['apps.shopify.com/kaching-bundles', 'apps.shopify.com/fast-bundle']));
        $this->assertTrue(BacklinkDiscovery::ownsListing('www.icartupsell.com', ['apps.shopify.com/icart-cart-drawer-cart-upsell']));
        $this->assertTrue(BacklinkDiscovery::ownsListing('bundler.app', ['apps.shopify.com/bundler-product-bundles']));
        $this->assertFalse(BacklinkDiscovery::ownsListing('essential-apps.com', ['apps.shopify.com/kaching-bundles', 'apps.shopify.com/fast-bundle']));
        $this->assertFalse(BacklinkDiscovery::ownsListing('knocommerce.com', ['rival.com']), 'plain domains are handled separately');
    }
}
