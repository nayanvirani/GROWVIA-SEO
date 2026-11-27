<?php

namespace Tests\Unit;

use App\Domain\Backlink\ContactFinder;
use Tests\TestCase;

class ContactFinderTest extends TestCase
{
    public function test_prefers_submit_pages_finds_emails_and_skips_paid_or_external_links()
    {
        $html = '<a href="/advertise">Advertise with us</a><a href="https://other.com/contact">Partner contact</a>'
            .'<a href="/contact-us">Contact</a><a href="/write-for-us">Write for us</a>'
            .'<a href="mailto:editor@blog.example.com?subject=hi">Email</a><a href="mailto:privacy@blog.example.com">Privacy</a>';
        $r = (new ContactFinder)->scan($html, 'https://blog.example.com/best-apps');

        $this->assertSame('https://blog.example.com/write-for-us', $r['url']);
        $this->assertSame('submit', $r['kind']);
        $this->assertSame(['editor@blog.example.com'], $r['emails']);

        $r = (new ContactFinder)->scan('<a href="/get-in-touch">Get in touch</a>', 'https://x.example.com/post');
        $this->assertSame(['url' => 'https://x.example.com/get-in-touch', 'kind' => 'contact', 'emails' => []], $r);
    }
}
