"""HTML metadata extraction. Page content is untrusted data and is never executed or obeyed."""

from __future__ import annotations

import hashlib
import re
from urllib.parse import urljoin, urlsplit

from bs4 import BeautifulSoup

try:
    import lxml  # noqa: F401

    PARSER = "lxml"
except ImportError:  # pragma: no cover
    PARSER = "html.parser"

MAX_LINKS = 500
MAX_IMAGES = 200
EXCERPT_CHARS = 4000


def _clean(text: str | None) -> str | None:
    if text is None:
        return None
    text = re.sub(r"\s+", " ", text).strip()
    return text


def extract(html: str, page_url: str) -> dict:
    soup = BeautifulSoup(html, PARSER)
    base_tag = soup.find("base", href=True)
    base = urljoin(page_url, base_tag["href"]) if base_tag else page_url

    title_tag = soup.find("title")
    title = _clean(title_tag.get_text()) if title_tag else None

    def meta(name: str) -> str | None:
        tag = soup.find("meta", attrs={"name": re.compile(f"^{name}$", re.I)})
        return _clean(tag.get("content")) if tag and tag.get("content") is not None else None

    robots = [v for v in (meta("robots"), meta("googlebot")) if v]

    canonical = None
    for link in soup.find_all("link", href=True):
        rel = [r.lower() for r in (link.get("rel") or [])]
        if "canonical" in rel:
            canonical = urljoin(base, link["href"].strip())
            break

    headings = {f"h{i}": [_clean(h.get_text()) for h in soup.find_all(f"h{i}")][:20] for i in range(1, 4)}

    links = []
    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if not href or href.startswith(("#", "javascript:", "mailto:", "tel:", "data:")):
            continue
        absolute = urljoin(base, href)
        if urlsplit(absolute).scheme not in ("http", "https"):
            continue
        rel = a.get("rel")
        links.append({
            "url": absolute.split("#")[0],
            "anchor": (_clean(a.get_text()) or a.get("aria-label") or a.get("title") or "")[:500],
            "rel": " ".join(rel) if isinstance(rel, list) else rel,
        })
        if len(links) >= MAX_LINKS:
            break

    images = []
    for img in soup.find_all("img"):
        src = img.get("src") or img.get("data-src")
        if not src:
            continue
        images.append({"src": urljoin(base, src)[:1000], "alt": img.get("alt")})
        if len(images) >= MAX_IMAGES:
            break

    for tag in soup(["script", "style", "noscript", "template", "svg"]):
        tag.decompose()
    body = soup.body or soup
    text = _clean(body.get_text(" ")) or ""
    words = re.findall(r"\w+", text)

    return {
        "title": title,
        "meta_description": meta("description"),
        "robots_meta": ", ".join(robots) or None,
        "canonical": canonical,
        "h1": headings["h1"],
        "headings": {"h2": headings["h2"], "h3": headings["h3"]},
        "links": links,
        "images": images,
        "word_count": len(words),
        "content_hash": hashlib.sha256(text.lower().encode()).hexdigest(),
        "text_excerpt": text[:EXCERPT_CHARS],
    }


def sitemap_locs(xml: str) -> tuple[list[str], list[str]]:
    """Return (page_urls, child_sitemaps) from a sitemap or sitemap index."""
    soup = BeautifulSoup(xml, "xml") if PARSER == "lxml" else BeautifulSoup(xml, "html.parser")
    pages, children = [], []
    for sm in soup.find_all("sitemap"):
        loc = sm.find("loc")
        if loc and loc.get_text(strip=True):
            children.append(loc.get_text(strip=True))
    for url in soup.find_all("url"):
        loc = url.find("loc")
        if loc and loc.get_text(strip=True):
            pages.append(loc.get_text(strip=True))
    return pages, children
