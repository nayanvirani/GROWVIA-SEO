import httpx

from app.crawler import MAX_BODY_BYTES, CrawlConfig, Crawler, CrawlState

PAGES = {
    "/robots.txt": (200, "text/plain", "User-agent: *\nDisallow: /private\nSitemap: https://site.com/sitemap.xml"),
    "/sitemap.xml": (200, "application/xml", "<urlset><url><loc>https://site.com/from-sitemap</loc></url></urlset>"),
    "/": (200, "text/html", '<title>Home</title><a href="/a">A</a><a href="/a">A again</a><a href="/private/x">P</a>'
                            '<a href="/old">Old</a><a href="/missing">M</a><a href="https://other.com/">Off</a>'),
    "/a": (200, "text/html", '<title>A</title><a href="/">Home</a>'),
    "/from-sitemap": (200, "text/html", "<title>SM</title>"),
    "/new": (200, "text/html", "<title>New</title>"),
    "/big": (200, "text/html", "x" * (MAX_BODY_BYTES + 10)),
}


def handler(request: httpx.Request) -> httpx.Response:
    path = request.url.path
    if path == "/old":
        return httpx.Response(301, headers={"location": "/new"})
    if path == "/away":
        return httpx.Response(302, headers={"location": "http://127.0.0.1/admin"})
    if path in PAGES:
        code, ctype, body = PAGES[path]
        return httpx.Response(code, headers={"content-type": ctype}, text=body)
    return httpx.Response(404, headers={"content-type": "text/html"}, text="nope")


async def no_sleep(_):
    return None


async def crawl(**kw):
    state = CrawlState()
    cfg = CrawlConfig(start_url="https://site.com/", allowed_host="site.com", **kw)
    await Crawler(cfg, state, transport=httpx.MockTransport(handler), resolver=lambda h: ["93.184.216.34"], sleep=no_sleep).run()
    return state


async def test_crawl_respects_robots_dedupes_and_follows_redirects():
    state = await crawl()
    urls = {p["url"] for p in state.pages}
    assert state.status == "completed"
    assert "https://site.com/private/x" not in urls
    assert "https://site.com/from-sitemap" in urls
    assert len([p for p in state.pages if p["url"] == "https://site.com/a"]) == 1
    old = next(p for p in state.pages if p["url"] == "https://site.com/old")
    assert old["final_url"] == "https://site.com/new" and old["redirect_chain"][0]["status"] == 301
    assert next(p for p in state.pages if p["url"] == "https://site.com/missing")["status_code"] == 404
    assert not any("other.com" in u for u in urls)
    assert state.sitemaps == ["https://site.com/sitemap.xml"]


async def test_max_pages_marks_partial():
    state = await crawl(max_pages=2)
    assert len(state.pages) == 2 and state.status == "partial"


async def test_oversized_response_recorded_as_error():
    state = CrawlState()
    c = Crawler(CrawlConfig(start_url="https://site.com/big", allowed_host="site.com", respect_robots=False, max_depth=0),
                state, transport=httpx.MockTransport(handler), resolver=lambda h: ["93.184.216.34"], sleep=no_sleep)
    await c.run()
    big = next(p for p in state.pages if p["url"] == "https://site.com/big")
    assert big["status_code"] is None and "larger" in big["error"]


async def test_private_start_url_fails():
    state = CrawlState()
    c = Crawler(CrawlConfig(start_url="https://site.com/", allowed_host="site.com"), state,
                transport=httpx.MockTransport(handler), resolver=lambda h: ["10.0.0.1"], sleep=no_sleep)
    await c.run()
    assert state.status == "failed" and state.pages == []
