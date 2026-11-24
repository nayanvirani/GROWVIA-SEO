"""Disk persistence for crawl results so a crawler restart does not lose collected pages.

Layout per crawl: <data_dir>/<crawl_id>/pages.jsonl (one page per line, appended as crawled)
and meta.json (status, errors, sitemaps, timestamps; rewritten atomically).
"""

from __future__ import annotations

import json
import os
import re
import shutil
import time
from pathlib import Path

from .crawler import CrawlState

CRAWL_ID = re.compile(r"^crawl_[0-9a-f]{32}$")


def data_dir() -> Path:
    default = Path(__file__).resolve().parent.parent / "data"
    path = Path(os.getenv("CRAWLER_DATA_DIR", str(default)))
    path.mkdir(parents=True, exist_ok=True)
    return path


def attach(state: CrawlState, crawl_id: str, root: Path) -> CrawlState:
    """Make state persist under root/crawl_id and write initial metadata."""
    folder = root / crawl_id
    folder.mkdir(parents=True, exist_ok=True)
    state.storage_dir = folder
    state.started_at = state.started_at or time.time()
    save_meta(state)
    return state


def append_page(state: CrawlState, page: dict) -> None:
    if state.storage_dir is None:
        return
    with open(state.storage_dir / "pages.jsonl", "a", encoding="utf-8") as fh:
        fh.write(json.dumps(page, ensure_ascii=False, separators=(",", ":")) + "\n")


def save_meta(state: CrawlState) -> None:
    if state.storage_dir is None:
        return
    meta = {
        "status": state.status,
        "errors": state.errors[:500],
        "sitemaps": state.sitemaps,
        "pages_found": state.pages_found,
        "started_at": state.started_at,
        "finished_at": state.finished_at,
    }
    tmp = state.storage_dir / "meta.json.tmp"
    tmp.write_text(json.dumps(meta), encoding="utf-8")
    os.replace(tmp, state.storage_dir / "meta.json")


def load_all(root: Path, ttl_seconds: int) -> dict[str, CrawlState]:
    """Reload persisted crawls. Crawls interrupted mid-run become 'partial' with an explanatory error."""
    jobs: dict[str, CrawlState] = {}
    now = time.time()
    for folder in root.iterdir():
        if not folder.is_dir() or not CRAWL_ID.match(folder.name):
            continue
        meta_file = folder / "meta.json"
        try:
            meta = json.loads(meta_file.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            shutil.rmtree(folder, ignore_errors=True)
            continue
        finished = meta.get("finished_at")
        if finished and now - finished > ttl_seconds:
            shutil.rmtree(folder, ignore_errors=True)
            continue

        pages = []
        pages_file = folder / "pages.jsonl"
        if pages_file.exists():
            with open(pages_file, encoding="utf-8") as fh:
                for line in fh:
                    try:
                        pages.append(json.loads(line))
                    except ValueError:
                        break  # a torn final line from a crash; keep what is valid

        state = CrawlState(
            status=meta.get("status", "failed"),
            pages=pages,
            errors=meta.get("errors", []),
            sitemaps=meta.get("sitemaps", []),
            pages_found=max(meta.get("pages_found", 0), len(pages)),
            finished_at=finished,
            started_at=meta.get("started_at"),
        )
        state.storage_dir = folder
        if state.status == "running":
            state.status = "partial" if pages else "failed"
            state.errors.append({"url": "", "error": "Crawler service restarted; crawl stopped early"})
            state.finished_at = now
            save_meta(state)
        jobs[folder.name] = state
    return jobs


def remove(state: CrawlState) -> None:
    if state.storage_dir is not None:
        shutil.rmtree(state.storage_dir, ignore_errors=True)
