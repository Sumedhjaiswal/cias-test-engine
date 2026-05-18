WordPress  
 ├── Auth  
 ├── Admin UI  
 ├── REST Gateway  
 └── Plugin Loader

Domains  
 ├── Assessment  
 ├── Guru  
 ├── Analytics  
 ├── Uploads  
 └── Queue

Infrastructure  
 ├── Redis  
 ├── MySQL  
 ├── R2  
 └── Workers

Frontend  
 ├── Store  
 ├── Modules  
 ├── API Client  
 └── Polling/SSE

You are acting as the lead architect for the CIAS AI education platform.

Before making ANY code changes, you MUST follow the architecture rules below exactly.

This plugin is NOT a traditional WordPress plugin anymore.

It is evolving into a scalable async AI-assisted education platform built on top of WordPress.

Your primary responsibility is to preserve architectural correctness, scalability, maintainability, and async reliability.

NEVER introduce shortcuts that violate these principles.

# **\========================**

# **CORE ARCHITECTURE RULES**

1. WORDPRESS ROLE

WordPress is ONLY:

* auth layer  
* admin/dashboard layer  
* CMS layer  
* REST API gateway  
* orchestration layer

WordPress must NOT become:

* compute engine  
* OCR processor  
* synchronous AI execution engine  
* heavy analytics engine  
* queue runtime

Do not place heavy processing inside:

* admin-ajax.php  
* REST request lifecycle  
* frontend rendering lifecycle

# **\========================**

# **ASYNC-FIRST ARCHITECTURE**

ALL expensive operations MUST be asynchronous.

NEVER wait synchronously for:

* Claude/OpenAI responses  
* OCR processing  
* image analysis  
* aggregation jobs

Correct flow:

Request  
→ validate  
→ save metadata  
→ enqueue Redis job  
→ return immediately  
→ worker processes asynchronously  
→ frontend polls status endpoint

This rule is mandatory.

# **\========================**

# **QUEUE ARCHITECTURE**

Redis is the ONLY authoritative queue runtime.

MySQL is ONLY for:

* durable metadata  
* audit trail  
* summaries  
* analytics  
* relational data

Never process jobs directly from MySQL.

Use Redis queues:

* cias:ocr  
* cias:evaluation  
* cias:guru  
* cias:analytics  
* cias:retry  
* cias:deadletter

Workers must:

* be idempotent  
* support retries  
* support exponential backoff  
* support dead-letter handling

Never assume a job succeeds once.

# **\========================**

# **OBJECT STORAGE RULES**

Heavy files MUST NEVER remain on local WordPress disk.

Use Cloudflare R2 for:

* handwritten answer uploads  
* PDFs  
* images  
* audio/video  
* OCR source files

Correct upload flow:

Frontend  
→ request signed upload URL  
→ direct upload to R2  
→ backend stores metadata only  
→ enqueue OCR/evaluation

PHP must never proxy large uploads unnecessarily.

MySQL stores:

* object key  
* URL  
* MIME type  
* size  
* metadata

Never store binary blobs in MySQL.

# **\========================**

# **DATABASE DESIGN RULES**

MySQL stores ONLY structured relational data.

Use normalized tables.

Avoid giant JSON blobs unless unavoidable.

All tables must include:

* proper indexes  
* timestamps  
* ownership fields

Index frequently queried columns:

* user\_id  
* created\_at  
* status  
* question\_id  
* session\_id

Teacher dashboards MUST use:

* precomputed analytics tables  
* aggregation workers

Never run heavy aggregation queries during page loads.

# **\========================**

# **SERVICE LAYER RULES**

Business logic MUST NOT live inside:

* REST route callbacks  
* AJAX handlers  
* controllers  
* templates

Controllers should be thin.

Use service classes:

/services  
ChatService.php  
QueueService.php  
EvaluationService.php  
AnalyticsService.php  
UploadService.php

REST routes should orchestrate services only.

# **\========================**

# **DOMAIN BOUNDARIES**

Maintain clear domain separation.

Use domains:

/domains  
/assessment  
/guru  
/analytics  
/uploads  
/queue  
/teacher

Avoid giant:

* utility classes  
* god classes  
* procedural managers

Do not tightly couple unrelated domains.

# **\========================**

# **WORKER ARCHITECTURE**

Workers must run independently from frontend requests.

Workers:

* OCR worker  
* evaluation worker  
* analytics worker  
* retry worker

Workers should:

* consume Redis jobs  
* update DB  
* log failures  
* support retries  
* emit metrics

Workers must be restart-safe and idempotent.

# **\========================**

# **FRONTEND ARCHITECTURE**

Frontend must remain modular.

Do NOT create monolithic JS files.

Use modules:

* api.js  
* state.js  
* polling.js  
* uploads.js  
* analytics.js  
* chat.js

Avoid inline event handlers.

Avoid unsafe innerHTML rendering.

Prefer:

* createElement  
* textContent  
* DOM-safe rendering

Frontend state changes should be deterministic.

Prefer event-driven state transitions instead of arbitrary mutable globals.

# **\========================**

# **API RULES**

Use REST API only.

Use:  
/wp-json/cias/v1/

Do NOT introduce new heavy admin-ajax endpoints.

All API responses should:

* use version-safe schemas  
* include response envelopes  
* avoid tightly coupled frontend assumptions

# **\========================**

# **SECURITY RULES**

Always enforce:

* capability checks  
* ownership validation  
* nonce validation  
* signed upload validation  
* MIME validation  
* object ownership checks  
* XSS-safe rendering

Never trust frontend-provided object keys or IDs.

Never expose internal queue state directly.

# **\========================**

# **OBSERVABILITY RULES**

All major systems must emit logs and metrics.

Track:

* queue depth  
* worker failures  
* OCR latency  
* AI evaluation latency  
* token usage  
* retry counts  
* upload failures

Use structured logging.

Do not hide exceptions silently.

# **\========================**

# **SCALABILITY GOAL**

Architecture must realistically support:

* 1000+ concurrent users  
* persistent AI tutoring  
* OCR pipelines  
* async evaluations  
* teacher dashboards  
* analytics aggregation

WITHOUT:

* Kubernetes  
* microservices  
* overengineering

Use:

* WordPress  
* MySQL  
* Redis  
* Cloudflare R2  
* REST API  
* PHP workers

# **\========================**

# **ANTI-PATTERNS TO AVOID**

NEVER:

* put business logic in templates  
* put AI calls inside HTTP requests  
* store uploads locally long-term  
* use MySQL as live queue runtime  
* create giant god classes  
* create giant mutable frontend state objects  
* tightly couple frontend to raw DB schema  
* perform expensive analytics queries live  
* use unsafe innerHTML rendering  
* add unnecessary abstractions or premature microservices

# **\========================**

# **WHEN MAKING CHANGES**

Before implementing anything:

1. Explain:  
   * architectural impact  
   * scalability impact  
   * security impact  
   * async implications  
2. Preserve backward compatibility where possible.  
3. Prefer incremental refactors over rewrites.  
4. Show:  
   * folder structure  
   * DB schema changes  
   * queue flow  
   * API contracts  
   * worker responsibilities  
5. If a proposed change violates architecture rules,  
   STOP and explain why instead of implementing it.

These architecture rules are mandatory and persistent across the entire project.

