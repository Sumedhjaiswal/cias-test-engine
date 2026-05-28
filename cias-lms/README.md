# CIAS LMS Plugin

Part of the **CIAS Test Engine Ecosystem**. Separate plugin, hooks into CIAS core via shared WordPress SSO and Redis.

---

## Features
- Video lectures (Vimeo private, domain-locked, signed tokens)
- PDF study material (Cloudflare R2, canvas-rendered, no download)
- Live Zoom classes (via Composio)
- Quizzes / tests (integrated with CIAS test engine)
- Student progress tracking
- WhatsApp notifications (AiSensy direct)

## Maximum Content Protection
| Layer | What it does |
|---|---|
| Vimeo private + domain lock | Video only playable on your domain |
| Server-side signed tokens (15min TTL, single-use) | Raw Vimeo ID never reaches client |
| Dynamic floating watermark | Student name + phone on every frame |
| Canvas PDF rendering | No native PDF viewer, no print, no download |
| Screen recording detection | Tab hidden → pause + log + warning |
| DevTools detection | Two methods: window size + debugger timing |
| Keyboard shortcut blocking | PrtSc, Cmd+Shift+4, Ctrl+P, F12 all blocked |
| Session fingerprinting | IP + user-agent + timestamp per session |
| Auto-revoke | 5 security events → session revoked |

## Architecture Rules
- **WordPress**: auth + REST gateway only
- **Vimeo**: video hosting, domain-locked embeds
- **R2**: PDF storage via pre-signed URLs
- **Redis**: session tokens (shared with CIAS core queues)
- **MySQL**: metadata, audit, progress only
- **Composio**: Zoom + Google Calendar only (peripheral)
- **AiSensy**: WhatsApp direct REST (not via Composio)

## Setup

1. Install after CIAS Test Engine plugin is active
2. Add constants to `wp-config.php` (see `includes/config-template.php`)
3. Activate — DB tables created automatically
4. Upload Vimeo videos as **Private** with domain restriction set to your domain
5. Upload PDFs to your R2 bucket

## REST Endpoints
All under `/wp-json/cias/v1/lms/`

| Method | Endpoint | Auth |
|---|---|---|
| GET | /courses | student |
| GET | /courses/:id | student + enrolled |
| POST | /enroll | student |
| POST | /video-token | student + enrolled |
| POST | /pdf-token | student + enrolled |
| POST | /zoom-link | student + enrolled |
| POST | /progress | student |
| GET | /progress/:course_id | student |
| POST | /security-event | student |
| POST | /admin/courses | teacher |
| POST | /admin/lessons | teacher |
