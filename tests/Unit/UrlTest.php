<?php

namespace Tests\Unit;

use App\Domain\Backlink\ProspectScorer;
use App\Support\Security\HostGuard;
use App\Support\Url;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    public function test_normalization()
    {
        $this->assertSame('https://example.com/', Url::normalize('Example.COM'));
        $this->assertSame('https://example.com/a/b?x=1', Url::normalize('https://example.com:443//a//b?x=1#frag'));
        $this->assertSame('http://example.com:8080/', Url::normalize('http://example.com:8080'));
        $this->assertSame('example.com', Url::host('https://www.example.com/x'));
        $this->assertSame(Url::hash('https://example.com'), Url::hash('https://EXAMPLE.com/#top'));
        $this->assertTrue(Url::sameSite('https://www.example.com/a', 'example.com'));
        $this->assertFalse(Url::sameSite('https://evil-example.com/a', 'example.com'));
    }

    public function test_host_guard()
    {
        foreach (['http://localhost', 'http://127.0.0.1', 'http://10.1.1.1', 'http://172.16.0.1', 'http://192.168.1.1', 'http://169.254.169.254',
            'http://100.64.0.1', 'http://[::1]', 'http://[fd00::1]', 'file:///etc/passwd', 'http://u:p@example.com', 'http://router', 'http://a.internal'] as $url) {
            $this->assertFalse(HostGuard::isAllowedUrl($url, resolve: false), $url);
        }
        $this->assertTrue(HostGuard::isAllowedUrl('https://example.com/', resolve: false));
        $this->assertTrue(HostGuard::isAllowedUrl('http://8.8.8.8/', resolve: false));
    }

    public function test_prospect_score_never_exceeds_100()
    {
        $r = (new ProspectScorer)->score(['source_url' => 'https://shopify-ecommerce-store.com/blog/merchant-conversion-upsell-cart-checkout',
            'page_title' => 'Shopify ecommerce store owner merchant seller founder brand shop guide', 'domain_rating' => 100,
            'organic_traffic' => 10000000, 'last_updated' => date('Y-m-d'), 'outbound_links' => 10]);
        $this->assertLessThanOrEqual(100, $r['score']);
        $this->assertSame([], $r['risk_flags']);
    }
}
