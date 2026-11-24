"""Polite, bounded, same-site crawler (guide section 7)."""

from __future__ import annotations

import asyncio
import time
import urllib.robotparser
from collections import deque
from dataclasses import dataclass, field
from urllib.parse import urljoin, urlsplit

import httpx

from .extractors import extract, sitemap_locs
from .safety import Resolver, UnsafeUrlError, default_resolver, normalize_url, site_host, validate_url

MAX_BODY_BYTES = 5 * 1024 * 1024
MAX_SITEMAP_BYTES = 10 * 1024 * 1024
MAX_REDIRECTS = 5
MIN_DELAY = 1.0
MAX_CRAWL_DELAY = 10.0
TIMEOUT = httpx.Timeout(20.0, connect=10.0)
SKIP_EXTENSIONS = (
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".ico", ".pdf", ".zip", ".gz", ".mp4", ".mp3",
    ".css", ".js", ".woff", ".woff2", ".ttf", ".xml", ".json", ".avif", ".dmg", ".exe",
)


@dataclass
class CrawlConfig:
    start_url: str
    allowed_host: str
    max_pages: int = 300
    max_depth: int = 5
    respect_robots: bool = True
    user_agent: str = "GrowviaSEOBot/1.0"
    exclusion_paths: list[str] = field(default_factory=list)


@dataclass
class FetchResult:
    url: str
    final_url: str
    status_code: int | None
    headers: dict
    body: bytes
    redirect_chain: list[dict]
    error: str | None = None


@dataclass
class CrawlState:
    status: str = "running"  # running | completed | partial | failed
    pages: list[dict] = field(default_factory=list)
    errors: list[dict] = field(default_factory=list)
    sitemaps: list[str] = field(default_factory=list)
    pages_found: int = 0
    finished_at: float | None = None


class TooLarge(Exception):
    pass


class Crawler:
    def __init__(self, config: CrawlConfig, state: CrawlState, transport: httpx.AsyncBaseTransport | None = None,
                 resolver: Resolver = default_resolver, sleep=asyncio.sleep):
        self.cfg = config
        self.state = state
        self.resolver = resolver
        self.sleep = sleep
        self.host = site_host(config.allowed_host)
        self.delay = MIN_DELAY
        self._last_request = 0.0
        self.robots: urllib.robotparser.RobotFileParser | None = None
        self.client = httpx.AsyncClient(
            transport=transport, follow_redirects=False, timeout=TIMEOUT,
            headers={"User-Agent": config.user_agent, "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.5"},
        )

    def same_site(self, url: str) -> bool:
        return site_host(urlsplit(url).hostname or "") == self.host

    def excluded(self, url: str) -> bool:
        path = urlsplit(url).path or "/"
        return any(path.startswith(p) for p in self.cfg.exclusion_paths if p)

    async def _throttle(self) -> None:
        wait = self._last_request + self.delay - time.monotonic()
        if wait > 0:
            await self.sleep(wait)
        self._last_request = time.monotonic()

    async def fetch(self, url: str, max_bytes: int = MAX_BODY_BYTES) -> FetchResult:
        chain: list[dict] = []
        current = url
        for _ in range(MAX_REDIRECTS + 1):
            current = validate_url(current, self.resolver)  # re-checked on every hop
            await self._throttle()
            async with self.client.stream("GET", current) as resp:
                if resp.is_redirect and "location" in resp.headers:
                    nxt = normalize_url(resp.headers["location"], current)
                    chain.append({"url": current, "status": resp.status_code})
                    if not self.same_site(nxt):
                        return FetchResult(url, nxt, resp.status_code, dict(resp.headers), b"", chain, "Redirects off-site")
                    current = nxt
                    continue
                declared = int(resp.headers.get("content-length") or 0)
                if declared > max_bytes:
                    raise TooLarge(f"Response larger than {max_bytes} bytes")
                body = bytearray()
                async for chunk in resp.aiter_bytes():
                    body.extend(chunk)
                    if len(body) > max_bytes:
                        raise TooLarge(f"Response larger than {max_bytes} bytes")
                return FetchResult(url, current, resp.status_code, dict(resp.headers), bytes(body), chain)
        return FetchResult(url, current, None, {}, b"", chain, f"More than {MAX_REDIRECTS} redirects")

    async def load_robots(self, origin: str) -> None:
        rp = urllib.robotparser.RobotFileParser()
        try:
            res = await self.fetch(origin + "/robots.txt", max_bytes=512 * 1024)
            code = res.status_code or 0
            if code >= 500 or code == 429:
                rp.disallow_all = True  # server error: treat as temporary full disallow
                self.state.errors.append({"url": res.final_url, "error": f"robots.txt returned {code}; crawl paused"})
            elif code >= 400:
                rp.allow_all = True
            else:
                rp.parse(res.body.decode("utf-8", "replace").splitlines())
        except (httpx.HTTPError, UnsafeUrlError, TooLarge) as exc:
            rp.allow_all = True
            self.state.errors.append({"url": origin + "/robots.txt", "error": str(exc)})
        self.robots = rp
        crawl_delay = rp.crawl_delay(self.cfg.user_agent) if not (rp.allow_all or rp.disallow_all) else None
        if crawl_delay:
            self.delay = min(max(float(crawl_delay), MIN_DELAY), MAX_CRAWL_DELAY)

    def allowed_by_robots(self, url: str) -> bool:
        if not self.cfg.respect_robots or self.robots is None:
            return True
        return self.robots.can_fetch(self.cfg.user_agent, url)

    async def discover_sitemaps(self, origin: str) -> list[str]:
        candidates = list(self.robots.site_maps() or []) if self.robots and not self.robots.disallow_all else []
        if not candidates:
            candidates = [origin + "/sitemap.xml"]
        found_pages: list[str] = []
        queue = deque(candidates[:5])
        checked = 0
        while queue and checked < 10 and len(found_pages) < self.cfg.max_pages * 2:
            sm = normalize_url(queue.popleft())
            checked += 1
            if not self.same_site(sm):
                continue
            try:
                res = await self.fetch(sm, max_bytes=MAX_SITEMAP_BYTES)
            except (httpx.HTTPError, UnsafeUrlError, TooLarge) as exc:
                self.state.errors.append({"url": sm, "error": f"sitemap: {exc}"})
                continue
            if res.status_code != 200 or not res.body:
                continue
            pages, children = sitemap_locs(res.body.decode("utf-8", "replace"))
            if pages or children:
                self.state.sitemaps.append(sm)
            found_pages.extend(pages)
            queue.extend(children)
        return found_pages

    def _record(self, depth: int, res: FetchResult) -> dict:
        ctype = res.headers.get("content-type", "")
        page = {
            "url": res.url,
            "final_url": res.final_url,
            "status_code": res.status_code,
            "content_type": ctype,
            "x_robots_tag": res.headers.get("x-robots-tag"),
            "depth": depth,
            "redirect_chain": res.redirect_chain,
            "error": res.error,
            "links": [],
        }
        if res.status_code and 200 <= res.status_code < 300 and "html" in ctype.lower() and res.body:
            encoding = "utf-8"
            if "charset=" in ctype.lower():
                encoding = ctype.lower().split("charset=")[-1].split(";")[0].strip() or "utf-8"
            try:
                html = res.body.decode(encoding, "replace")
            except LookupError:
                html = res.body.decode("utf-8", "replace")
            page.update(extract(html, res.final_url))
        return page

    async def run(self) -> None:
        try:
            start = validate_url(self.cfg.start_url, self.resolver)
            parts = urlsplit(start)
            origin = f"{parts.scheme}://{parts.netloc}"
            if self.cfg.respect_robots:
                await self.load_robots(origin)
                if self.robots and self.robots.disallow_all:
                    self.state.status = "failed"
                    return

            seen: set[str] = {start}
            queue: deque[tuple[str, int]] = deque([(start, 0)])
            for loc in await self.discover_sitemaps(origin):
                try:
                    u = normalize_url(loc)
                except ValueError:
                    continue
                if self.same_site(u) and u not in seen and not self.excluded(u):
                    seen.add(u)
                    queue.append((u, 1))

            crawled = 0
            while queue and crawled < self.cfg.max_pages:
                url, depth = queue.popleft()
                if not self.allowed_by_robots(url):
                    continue
                try:
                    res = await self.fetch(url)
                except UnsafeUrlError as exc:
                    self.state.errors.append({"url": url, "error": f"blocked: {exc}"})
                    continue
                except (httpx.HTTPError, TooLarge) as exc:
                    self.state.pages.append({"url": url, "status_code": None, "depth": depth, "error": str(exc) or exc.__class__.__name__, "links": []})
                    crawled += 1
                    continue
                page = self._record(depth, res)
                self.state.pages.append(page)
                crawled += 1

                if depth < self.cfg.max_depth:
                    for link in page.get("links", []):
                        try:
                            target = normalize_url(link["url"])
                        except ValueError:
                            continue
                        if (target not in seen and self.same_site(target) and not self.excluded(target)
                                and not urlsplit(target).path.lower().endswith(SKIP_EXTENSIONS)):
                            seen.add(target)
                            queue.append((target, depth + 1))
                self.state.pages_found = len(seen)

            self.state.pages_found = len(seen)
            self.state.status = "partial" if queue else "completed"
        except UnsafeUrlError as exc:
            self.state.errors.append({"url": self.cfg.start_url, "error": f"blocked: {exc}"})
            self.state.status = "failed"
        except Exception as exc:  # noqa: BLE001 - surface any failure as crawl failure
            self.state.errors.append({"url": self.cfg.start_url, "error": f"{exc.__class__.__name__}: {exc}"})
            self.state.status = "failed"
        finally:
            self.state.finished_at = time.time()
            await self.client.aclose()
