# Growvia AI SEO Manager

An SEO assistant for website owners who are not SEO specialists. **Autopilot** crawls the site,
explains technical problems in plain language, finds keyword ideas, plans and drafts articles, and
finds backlink opportunities, then turns it all into a to-do list. Everything runs on **free**
services. **Nothing is published or sent automatically**: you approve every draft and send every
outreach message yourself. No emails are sent by the app.

Built from `Growvia_AI_SEO_Manager_MVP_Implementation_Guide.docx`.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Inertia.js + Vue 3 + TypeScript + Tailwind |
| Database | MySQL 8 (`growvia_seo`) |
| Queue / cache | Database driver locally (switch to Redis + Horizon in production) |
| Crawler | Python 3.12+ FastAPI service in `crawler-service/` |
| AI writer | Any free OpenAI-compatible provider: Gemini, Groq, OpenRouter (`:free` models), Cerebras, Mistral, Ollama (local), or custom |
| Keyword ideas | Google + DuckDuckGo autocomplete (free, no key). No search volume is invented: ideas get an opportunity score |
| Backlink discovery | DuckDuckGo (no key, slow) or Tavily (free key), plus safe page checks |

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
                               ├─ queues: high, crawl, ai, default, low
                               ├─> crawler-service (Bearer token, /internal/v1/crawls)
                               ├─> AI provider (free OpenAI-compatible: Gemini, Groq, OpenRouter, Ollama…)
                               ├─> Google + DuckDuckGo autocomplete (keyword ideas)
                               └─> DuckDuckGo / Tavily / SearXNG (backlink discovery)
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

### Providers

Choose providers in the app under **AI & data providers** (owners/admins). Keys are stored encrypted
per workspace and never sent to the browser. The page loads each provider's live model list and has a
**Test** button. Until an AI is connected, the app uses an offline template mode.

### Autopilot

1. Crawl and audit the site.
2. Find topics from page titles, expand them with autocomplete, score every idea (popularity signal,
   relevance to the site, intent, and whether a page already covers it), map ideas to pages and create up
   to 3 article briefs for the best uncovered topics. With a real AI, it drafts the top one and
   double-checks relevance.
3. Search for "best <topic>" lists and resource pages, open them, detect competitors (apps several lists
   link to), find pages that already link to you, score opportunities and draft outreach for you to send.

## Scheduled automations

| Automation | Schedule |
|---|---|
| Autopilot (crawl → keywords → backlinks) | Weekly (Mon 02:00). Research steps also run after any crawl, at most weekly |
| Broken page recheck | Daily 03:00 |
| Weekly summary / draft reminder (in-app) | Weekly (Mon) |

Each run is recorded in the Automation Center. You can pause each automation per website.
