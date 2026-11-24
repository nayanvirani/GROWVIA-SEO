import importlib
import json
import time

import httpx
from fastapi.testclient import TestClient

from app import store
from app.crawler import CrawlConfig, Crawler, CrawlState
from tests.test_crawler import handler, no_sleep

ID = "crawl_" + "a" * 32


async def test_completed_crawl_survives_reload(tmp_path):
    state = store.attach(CrawlState(), ID, tmp_path)
    cfg = CrawlConfig(start_url="https://site.com/", allowed_host="site.com")
    await Crawler(cfg, state, transport=httpx.MockTransport(handler), resolver=lambda h: ["93.184.216.34"], sleep=no_sleep).run()

    loaded = store.load_all(tmp_path, ttl_seconds=3600)[ID]
    assert loaded.status == "completed"
    assert [p["url"] for p in loaded.pages] == [p["url"] for p in state.pages]
    assert loaded.sitemaps == state.sitemaps


def test_interrupted_crawl_becomes_partial_and_torn_line_is_ignored(tmp_path):
    state = store.attach(CrawlState(), ID, tmp_path)
    state.add_page({"url": "https://site.com/", "status_code": 200})
    with open(tmp_path / ID / "pages.jsonl", "a") as fh:
        fh.write('{"url": "https://site.com/hal')  # crash mid-write

    loaded = store.load_all(tmp_path, ttl_seconds=3600)[ID]
    assert loaded.status == "partial"
    assert len(loaded.pages) == 1
    assert "restarted" in loaded.errors[-1]["error"]
    assert json.loads((tmp_path / ID / "meta.json").read_text())["status"] == "partial"


def test_interrupted_crawl_without_pages_is_failed(tmp_path):
    store.attach(CrawlState(), ID, tmp_path)
    assert store.load_all(tmp_path, ttl_seconds=3600)[ID].status == "failed"


def test_expired_and_foreign_folders_are_removed_or_ignored(tmp_path):
    state = store.attach(CrawlState(), ID, tmp_path)
    state.status, state.finished_at = "completed", time.time() - 7200
    state.persist()
    (tmp_path / "not-a-crawl").mkdir()

    assert store.load_all(tmp_path, ttl_seconds=3600) == {}
    assert not (tmp_path / ID).exists()
    assert (tmp_path / "not-a-crawl").exists()


def test_api_serves_results_after_restart(tmp_path, monkeypatch):
    monkeypatch.setenv("CRAWLER_DATA_DIR", str(tmp_path))
    monkeypatch.setenv("CRAWLER_API_TOKEN", "t")
    state = store.attach(CrawlState(), ID, tmp_path)
    state.add_page({"url": "https://site.com/", "status_code": 200})

    import app.main as main

    main = importlib.reload(main)  # simulates a process restart
    client = TestClient(main.app)
    assert client.get(f"/internal/v1/crawls/{ID}/results").status_code == 401
    body = client.get(f"/internal/v1/crawls/{ID}/results", headers={"Authorization": "Bearer t"}).json()
    assert body["status"] == "partial" and body["done"] is True and len(body["pages"]) == 1
