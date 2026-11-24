# Growvia AI SEO Manager

An SEO assistant for website owners who are not SEO specialists. It crawls a site, explains
technical issues in plain language with evidence, researches keywords, drafts content with AI,
manages backlink prospects and tracks Search Console performance. **Nothing is published
automatically** — every draft and outreach email needs human approval.

Built from `Growvia_AI_SEO_Manager_MVP_Implementation_Guide.docx`.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Inertia.js + Vue 3 + TypeScript + Tailwind |
| Database | MySQL 8 (`growvia_seo`) |
| Queue / cache | Database driver locally (switch to Redis + Horizon in production) |
| Crawler | Python 3.12+ FastAPI service in `crawler-service/` |
| AI / SEO data / analytics | Provider adapters with deterministic mock providers |

## Local setup

```bash
composer install
npm install
cp .env.example .env            # set DB_PASSWORD, then:
php artisan key:generate
php artisan migrate --seed      # creates admin@growvia.test / password and the Growvia project
npm run build

# Crawler service (needs python3-venv: sudo apt install python3.14-venv)
cd crawler-service
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
cp .env.example .env            # CRAWLER_API_TOKEN must match Laravel's .env
cd ..

# Apache virtual host http://growvia-seo.test
sudo deploy/local/setup-vhost.sh
```

Run the background processes (queue worker, scheduler, Vite, crawler) in one terminal:

```bash
composer dev
```

Open http://growvia-seo.test and log in as `admin@growvia.test` / `password`.
Confirm ownership in **Website Settings** before the first crawl.

## Tests

```bash
php artisan test                                  # Laravel (SQLite in-memory)
cd crawler-service && .venv/bin/python -m pytest  # crawler safety, parsing, crawl rules
```

## Architecture

```
Browser (Vue/Inertia) ──> Laravel web + /api/v1 ──> MySQL
                               │
                               ├─ queues: high, crawl, ai, seo-data, analytics, default, low
                               ├─> crawler-service (Bearer token, /internal/v1/crawls)
                               ├─> AI provider (mock | openai-compatible)
                               ├─> SEO data provider (mock; DataForSEO adapter later)
                               └─> Google Search Console (OAuth, read-only)
```

- `app/Domain/*` — business logic per domain (Website, Crawl, Audit, Keyword, Content, Backlink, Analytics, Automation).
- `app/Providers/{AI,SEOData,Analytics}` — provider interfaces and adapters (section 17).
- `app/Http/Controllers/Web` — Inertia pages; `app/Http/Controllers/Api/V1` — JSON API (section 15).
- Every website-owned table carries `workspace_id` and `website_id`; access is enforced with policies and scoped route bindings.

### Crawl flow

1. `POST /websites/{id}/crawl` creates a `crawl_runs` row and queues `StartCrawl`.
2. The crawler validates the URL (scheme, DNS, private IP ranges, re-checked on every redirect), reads robots.txt and sitemaps, and crawls same-site pages with a 1s+ delay, 5 MB body cap, 5-redirect limit, and page and depth caps.
3. `PollCrawl` pulls page batches, upserts `website_pages` / `page_links`, and runs the audit engine when the crawl finishes.
4. Issues are resolved only when their page was re-checked by a successful crawl; failed crawls never resolve issues.

### Configuration

| Variable | Purpose |
|---|---|
| `CRAWLER_BASE_URL`, `CRAWLER_API_TOKEN` | Internal crawler service |
| `AI_PROVIDER` (`mock`/`openai`), `AI_API_KEY`, `AI_BASE_URL`, `AI_MODEL_SMALL`, `AI_MODEL_LARGE` | LLM adapter |
| `SEO_DATA_PROVIDER`, `SEO_DATA_API_KEY` | Keyword/backlink data provider |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Search Console OAuth (redirect: `/integrations/search-console/callback`) |

## Scheduled automations

| Automation | Schedule |
|---|---|
| Website crawl | Weekly (Mon 02:00) or monthly, per website setting |
| Critical issue recheck | Daily 03:00 |
| Keyword refresh / backlink recheck | Monthly |
| Search Console sync | Daily 06:00 |
| Weekly digest / stale draft reminder | Weekly (Mon) |

Each run is recorded in `automation_runs` and shown in the Automation Center. You can pause each automation per website.
