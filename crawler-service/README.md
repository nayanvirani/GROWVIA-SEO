# Crawler service

Internal FastAPI service that crawls a registered website politely and safely and returns
normalized page records to Laravel in batches. Laravel is the only client.

```bash
cd crawler-service
python3 -m venv .venv            # needs the python3-venv package on Ubuntu
.venv/bin/pip install -r requirements.txt
cp .env.example .env             # set CRAWLER_API_TOKEN to the same value as Laravel's .env
.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100
.venv/bin/python -m pytest
```

Endpoints (Bearer token required except `/health`):

- `POST /internal/v1/crawls` – start a crawl, returns `{crawl_id, status: "accepted"}`
- `GET /internal/v1/crawls/{id}/results?cursor=0&limit=50` – page batch plus `status`, `done`, `next_cursor`
- `GET /health`

Safety: http/https only, DNS re-checked before every request and redirect, private/reserved/metadata
IPs blocked, robots.txt respected, 1s+ per-host delay (honours Crawl-delay up to 10s), 5 MB body cap,
5 redirects max, same-site only, page and depth caps.

Persistence: each crawl is written to `data/<crawl_id>/` (`pages.jsonl` + `meta.json`, override with
`CRAWLER_DATA_DIR`) and kept for one hour after it finishes. After a restart, finished crawls are served
again; a crawl interrupted mid-run is reported as `partial` (or `failed` if it had no pages), so Laravel
keeps the pages it already collected and only resolves issues on pages that were actually rechecked.
