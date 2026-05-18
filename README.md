# CIAS Test Engine — UPSC AI Education Platform

A scalable async AI-assisted education platform built on WordPress.

## Architecture

```
WordPress (Auth · Admin · REST Gateway)
    ↓
REST API /wp-json/cias/v1/
    ↓
Job Queue (Redis → MySQL fallback)
    ↓
PHP CLI Workers (Cloudways Cron)
    ↓
Storage (MySQL · Cloudflare R2 · Redis)
```

**Core principle:** WordPress never executes AI, OCR, or heavy processing synchronously.
Every expensive operation is enqueued and processed by background workers.

## Plugin Structure

```
cias-test-engine/
├── cias-test-engine.php        # Main plugin — constants, DB bootstrap
├── ARCHITECTURE.md             # Mandatory architecture rules
├── includes/                   # Core classes
│   ├── class-cias-db.php
│   ├── class-cias-ai-guru.php
│   ├── class-cias-ai-utils.php
│   └── class-cias-ajax.php     # Legacy AJAX (deprecated gradually)
├── phase-a/                    # Chat history, credits, messaging
├── phase-b/                    # Queue, R2, OCR, Evaluation, REST API
│   ├── workers/                # PHP CLI workers (run via cron)
│   │   ├── worker-guru.php
│   │   ├── worker-ocr.php
│   │   ├── worker-evaluate.php
│   │   ├── worker-analytics.php
│   │   └── worker-retry.php
│   ├── class-cias-queue.php
│   ├── class-cias-r2.php
│   ├── class-cias-rest-*.php
│   └── class-cias-ops-monitor.php
└── phase-c/                    # Student-facing PWA
    ├── class-cias-frontend.php
    ├── class-cias-app-data.php
    ├── templates/app.php
    └── assets/
        ├── js/                 # Modular JS (one file per concern)
        │   ├── state.js
        │   ├── api.js
        │   ├── polling.js
        │   ├── chat.js
        │   ├── tests.js
        │   ├── uploads.js
        │   └── cias-app.js
        └── css/
            └── cias-app.css
```

## Architecture Rules

See [ARCHITECTURE.md](ARCHITECTURE.md) — these rules are mandatory for all changes.

**Summary:**
- WordPress = auth/admin/REST gateway ONLY
- All AI/OCR/evaluation calls are **async** — never inside HTTP requests
- Redis is the authoritative queue runtime
- Files go to Cloudflare R2 — never through PHP, never in MySQL
- Business logic lives in `/services/` — REST routes are thin orchestrators
- Frontend: modular JS only — no monolithic files, no inline handlers
- Before ANY change: explain architectural + scalability + security impact

## Deployment

**Server:** Cloudways Managed WordPress
**PHP:** 8.2+
**Queue:** Upstash Redis (falls back to MySQL if Redis unavailable)
**Storage:** Cloudflare R2

### Cron jobs (Cloudways Cron Manager)
```bash
# Run every minute
php /path/to/wp-content/plugins/cias-test-engine/phase-b/workers/worker-guru.php
php /path/to/wp-content/plugins/cias-test-engine/phase-b/workers/worker-ocr.php
php /path/to/wp-content/plugins/cias-test-engine/phase-b/workers/worker-evaluate.php
php /path/to/wp-content/plugins/cias-test-engine/phase-b/workers/worker-analytics.php
php /path/to/wp-content/plugins/cias-test-engine/phase-b/workers/worker-retry.php
```

## Branching Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Production — only tested, approved code |
| `staging` | Pre-production testing |
| `feature/phase-2-rest-tests` | Active development |

**Rule:** Never commit directly to `main`. Always create a feature branch, test on staging, then merge.

## Versioning

Format: `v{major}.{minor}.{patch}`

Current: see plugin header in `cias-test-engine.php`

Each version bump should include:
- What changed
- Which files were modified
- Whether DB migrations are needed (additive only — no destructive changes)

## Database

All schema changes are **additive only** — no DROP TABLE, no RENAME COLUMN in production.

New tables are created with `dbDelta()` on plugin activation/update.

See `includes/class-cias-db.php` and `phase-b/class-cias-db-phase-b.php`.
