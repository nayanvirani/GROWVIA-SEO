"""Internal crawler API (guide section 15.1). Only Laravel calls this service."""

from __future__ import annotations

import asyncio
import hmac
import os
import time
import uuid
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException, Query
from pydantic import BaseModel, Field

from . import store
from .crawler import CrawlConfig, Crawler, CrawlState
from .safety import UnsafeUrlError, site_host, validate_url


def _load_env_file() -> None:
    env = Path(__file__).resolve().parent.parent / ".env"
    if env.exists():
        for line in env.read_text().splitlines():
            if "=" in line and not line.lstrip().startswith("#"):
                key, _, value = line.partition("=")
                os.environ.setdefault(key.strip(), value.strip().strip('"'))


_load_env_file()

RESULT_TTL_SECONDS = 3600
MAX_CONCURRENT = int(os.getenv("CRAWLER_MAX_CONCURRENT_CRAWLS", "3"))

app = FastAPI(title="Growvia crawler service", version="1.0.0", docs_url=None, redoc_url=None)
_jobs: dict[str, CrawlState] = store.load_all(store.data_dir(), RESULT_TTL_SECONDS)
_semaphore = asyncio.Semaphore(MAX_CONCURRENT)


def require_token(authorization: str = Header(default="")) -> None:
    expected = os.getenv("CRAWLER_API_TOKEN", "")
    supplied = authorization.removeprefix("Bearer ").strip()
    if not expected or not hmac.compare_digest(supplied, expected):
        raise HTTPException(status_code=401, detail="Unauthorized")


class CrawlRequest(BaseModel):
    website_id: str
    start_url: str
    allowed_host: str | None = None
    max_pages: int = Field(300, ge=1, le=1000)
    max_depth: int = Field(5, ge=0, le=10)
    respect_robots: bool = True
    user_agent: str = Field("GrowviaSEOBot/1.0", max_length=200)
    exclusion_paths: list[str] = Field(default_factory=list, max_length=100)


def _cleanup() -> None:
    now = time.time()
    for key in [k for k, s in _jobs.items() if s.finished_at and now - s.finished_at > RESULT_TTL_SECONDS]:
        store.remove(_jobs.pop(key))


async def _run(crawler: Crawler) -> None:
    async with _semaphore:
        await crawler.run()


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "active_crawls": sum(1 for s in _jobs.values() if s.status == "running")}


@app.post("/internal/v1/crawls", dependencies=[Depends(require_token)], status_code=202)
async def start_crawl(req: CrawlRequest) -> dict:
    _cleanup()
    try:
        start = validate_url(req.start_url)
    except UnsafeUrlError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    from urllib.parse import urlsplit

    host = site_host(urlsplit(start).hostname or "")
    if req.allowed_host and site_host(req.allowed_host) != host:
        raise HTTPException(status_code=422, detail="start_url host does not match the registered website host")

    crawl_id = f"crawl_{uuid.uuid4().hex}"
    state = store.attach(CrawlState(), crawl_id, store.data_dir())
    _jobs[crawl_id] = state
    crawler = Crawler(CrawlConfig(
        start_url=start, allowed_host=host, max_pages=req.max_pages, max_depth=req.max_depth,
        respect_robots=req.respect_robots, user_agent=req.user_agent, exclusion_paths=req.exclusion_paths,
    ), state)
    asyncio.get_running_loop().create_task(_run(crawler))
    return {"crawl_id": crawl_id, "status": "accepted"}


@app.get("/internal/v1/crawls/{crawl_id}/results", dependencies=[Depends(require_token)])
def crawl_results(crawl_id: str, cursor: int = Query(0, ge=0), limit: int = Query(50, ge=1, le=200)) -> dict:
    state = _jobs.get(crawl_id)
    if state is None:
        raise HTTPException(status_code=404, detail="Unknown crawl")
    pages = state.pages[cursor:cursor + limit]
    next_cursor = cursor + len(pages)
    done = state.status != "running" and next_cursor >= len(state.pages)
    return {
        "status": state.status,
        "pages": pages,
        "next_cursor": next_cursor,
        "done": done,
        "errors": state.errors[:100] if done else [],
        "sitemaps": state.sitemaps,
        "pages_found": state.pages_found,
    }
