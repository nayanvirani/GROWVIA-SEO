<?php

namespace Tests\Unit;

use App\Domain\Audit\AuditEngine;
use PHPUnit\Framework\TestCase;

class RobotsRuleTest extends TestCase
{
    public function test_detects_blocked_ai_search_bots_in_grouped_rules()
    {
        $robots = "# Blocked\nUser-agent: GPTBot\nUser-agent: ChatGPT-User\nUser-agent: OAI-SearchBot\nUser-agent: PerplexityBot\nDisallow: /\n\n"
            ."User-agent: *\nDisallow: /app\nDisallow: /admin\nSitemap: https://x.test/sitemap.xml\n";

        $this->assertSame(['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot'], AuditEngine::blockedAgents($robots, AuditEngine::AI_SEARCH_AGENTS));
    }

    public function test_partial_rules_and_allow_overrides_are_not_flagged()
    {
        $robots = "User-agent: OAI-SearchBot\nDisallow: /admin\n\nUser-agent: PerplexityBot\nDisallow: /\nAllow: /\n\nUser-agent: GPTBot\nDisallow: /\n";
        $this->assertSame([], AuditEngine::blockedAgents($robots, AuditEngine::AI_SEARCH_AGENTS), 'training-only GPTBot is not a search bot');
    }
}
