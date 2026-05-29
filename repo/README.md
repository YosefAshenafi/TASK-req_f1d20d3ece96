# Campus Portal

Unified Campus Operations & Logistics Management Portal — coordinating offline events, order fulfillment, and accountability within a single on-premise site.

**Stack:** ThinkPHP 6.x (PHP 8.2) · Layui 2.x frontend · MySQL 8.0 · Docker Compose

---

## Quick Start

### Prerequisites

- Docker 24+ and Docker Compose V2 (or legacy `docker-compose`)
- No other local toolchain is required — everything runs inside containers
- **No internet access required at runtime.** Layui 2.9.16 UI assets are bundled locally under `frontend/vendor/layui/` and served by nginx without any CDN dependency.

### 1. Clone & start

```bash
git clone <repository-url>
cd repo
docker compose up --build -d
```

Legacy Compose (V1) is also supported:

```bash
docker-compose up --build -d
```

The portal is available at **http://localhost:3000**

### 2. Seed demo accounts

```bash
docker compose exec backend php think db:seed
```

| Username   | Password        | Role              |
|------------|-----------------|-------------------|
| admin      | Admin@Campus1   | Administrator     |
| ops_user   | Ops@Campus1     | Operations Staff  |
| team_lead  | Lead@Campus1    | Team Lead         |
| reviewer   | Review@Campus1  | Reviewer          |
| user1      | User@Campus1!   | Regular User      |
| user2      | User@Campus2!   | Regular User      |

### 3. Verify the application is running

After seeding, confirm the stack is healthy with these steps:

**Step 1 — Login and obtain a token:**
```bash
curl -s -X POST http://localhost:3000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"Admin@Campus1"}' | python3 -m json.tool
```
Expected: HTTP 200, response body contains `"code": 200` and a `"token"` string under `data`.

**Step 2 — List activities (authenticated):**
```bash
TOKEN=<paste token from step 1>
curl -s http://localhost:3000/api/activities \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```
Expected: HTTP 200, response body contains `"code": 200` and a `"data"` object with a `"data"` array (empty or populated depending on seed state).

**Step 3 — Verify role-based access control:**
```bash
curl -s -o /dev/null -w "%{http_code}" \
  http://localhost:3000/api/users \
  -H "Authorization: Bearer $TOKEN"
```
Expected: `200` for the admin token. Repeating the same request with a `user1` token returns `403`.

**Step 4 — Open the frontend:**
Navigate to **http://localhost:3000** in a browser and log in with `admin` / `Admin@Campus1`. The activity list, dashboard, and navigation should load without CDN requests (all assets are served locally).

### 4. Run the test suite

```bash
bash run_tests.sh
```

This script:
1. Builds all Docker images
2. Starts the full stack (db, backend, nginx)
3. Seeds demo accounts
4. Runs PHPUnit against the live HTTP endpoints
5. Runs the Node.js frontend unit tests (`tests/frontend/test-fmt.js`, `test-tags.js`, `test-render.js`) in an isolated `node:18-alpine` container
6. Tears down all containers on exit

> **No mocking.** Every API test sends real HTTP requests to the running ThinkPHP application. No controllers, services, or auth middleware are mocked. Frontend tests use Node.js built-in `assert` only — no npm dependencies.

---

## Architecture

```
repo/
├── backend/            # ThinkPHP 6.x PHP application
│   ├── app/
│   │   ├── controller/ # HTTP handlers (Auth, User, Activity, Order, …)
│   │   ├── service/    # Business logic (OrderService, ViolationService, …)
│   │   ├── model/      # ORM models
│   │   ├── middleware/ # Auth (JWT), BehaviorCapture, CORS
│   │   ├── validate/   # Input validation rules
│   │   ├── command/    # Console commands (auto-cancel, indexing, seeding)
│   │   └── exception/  # AppException hierarchy + global handler
│   ├── config/         # ThinkPHP config files
│   ├── route/api.php   # All 60 API routes
│   ├── public/         # Entry point (index.php)
│   ├── Dockerfile
│   └── entrypoint.sh   # Generates JWT_SECRET + ENCRYPTION_KEY at runtime
├── frontend/           # Static Layui HTML/CSS/JS (fully offline — no CDN)
│   ├── index.html      # Dashboard home
│   ├── pages/          # Per-feature pages (activities, orders, shipments, …)
│   ├── js/             # api.js, app.js (fmt() → MM/DD/YYYY h:mm AM/PM)
│   ├── css/campus.css  # Design-token stylesheet
│   └── vendor/layui/   # Layui 2.9.16 bundled locally (css/, font/, layui.min.js)
├── db/migrations/      # Ordered SQL migration files (001–013)
├── tests/frontend/     # Node.js unit tests (no npm); run via docker run node:18-alpine
├── nginx/default.conf  # Reverse proxy (/ → frontend, /api → backend)
├── docker-compose.yml
├── phpunit.xml
└── run_tests.sh
```

---

## API Overview

All endpoints are prefixed `/api`. Authentication uses `Authorization: Bearer <token>`.

| Group         | Endpoints                              |
|---------------|----------------------------------------|
| Auth          | POST login, POST logout                |
| Users         | CRUD + sensitive field access          |
| Activities    | CRUD + state, signups, saves, versions, tasks |
| Orders        | CRUD + state, refunds, corrections     |
| Shipments     | CRUD + events, delivery, exceptions    |
| Violations    | Rules, violations, evidence, appeals   |
| Search        | Global full-text, logistics (sort: relevance/recency/popularity/reply_count) |
| Recommendations | List + activity-detail context + order-detail context; includes order candidates |
| Dashboards    | CRUD, favorites, widget data (with drill-down), drag-drop layout, export |

### Search Sort Semantics

Both `/api/search` (global) and `/api/search/logistics` accept a `sort` query parameter:

| Sort value    | Global search (`search_index`)              | Logistics search (`logistics_index`)                        |
|---------------|---------------------------------------------|-------------------------------------------------------------|
| `relevance`   | MySQL FULLTEXT score (`MATCH … AGAINST`)    | PHP token-hit score: exact=2.0, spell-corrected=1.5, pinyin=1.0, synonym=0.5 per token |
| `recency`     | `indexed_at DESC`                           | `indexed_at DESC`                                           |
| `popularity`  | `view_count DESC`                           | `view_count DESC` (seeded 0; incremented externally)        |
| `reply_count` | `reply_count DESC`                          | `reply_count DESC` (corrections for orders; events for shipments) |

The four sort values map to **distinct** ordering logic. `sort=relevance` on the logistics endpoint scores every matching row in PHP across `display_name`/`pinyin_name` using per-token weights and then paginates the sorted set. The global endpoint uses MySQL FULLTEXT scoring.

### Logistics Search Authorization

`/api/search/logistics` enforces object-level authorization:

| Caller role | Visible results |
|-------------|-----------------|
| `admin`     | All orders and shipments |
| Any other role | Only orders where `orders.created_by = caller_user_id`; only shipments where `shipments.created_by = caller_user_id` |

This scoping is applied before counting, sorting, and pagination in both the relevance (PHP-scored) and non-relevance (SQL-ordered) branches.

### Recommendation Engine

Three recommendation endpoints are available:

| Endpoint | Context | Self-exclusion |
|----------|---------|----------------|
| `GET /api/recommendations` | Global list | None |
| `GET /api/recommendations/activities/:id` | Activity detail page | Excludes the current activity |
| `GET /api/recommendations/orders/:id` | Order detail page | Excludes the current order |

All three return `{ data: { items, is_cold_start } }`. Self-exclusion is entity-type-aware: requesting order-detail recommendations for order #7 never excludes activities that happen to have numeric id 7.

**Order visibility in recommendations:** Order candidates follow the same authorization rules as `GET /api/orders`. Admin users receive recommendations for all orders. Non-admin users (ops_staff, team_lead, reviewer, regular) only receive order candidates for orders they created (`orders.created_by = user_id`). Regular users who have not created any orders will receive no order-type recommendation items.

`/api/recommendations` returns a blended feed of activities and orders ranked by behavioral signals. Candidates are sourced from:
- **Activities** — scored by tag-signal match (from `behavior_events`) plus a `log(view_count+1) × 0.1` popularity bonus.
- **Orders** — scored by `log(view_count+1) × 0.15`; included in both cold-start and signal-based paths.

Each item in the response carries a **`family_id`** — an explicit stable identifier stored in the `activities.family_id` / `orders.family_id` columns and denormalised into the search/logistics index tables at indexing time:
- Activities created with tags: `tag:<lex-first-tag>` (computed once at creation from the alphabetically-first tag; never re-derived at query time).
- Untagged activities: `activity:<id>`.
- Orders: `order:<id>` (each order is its own family by default).

The engine reads `family_id` from the index row directly — no runtime tag heuristics. It never returns two items with the same `family_id`, and enforces a 40% per-tag diversity cap on top.

### Behavior Signal Sources

| Signal  | Source endpoint / event                     | `event_type` in `behavior_events` |
|---------|---------------------------------------------|-----------------------------------|
| `view`  | `GET /api/activities/{id}` (middleware)     | `view`                            |
| `save`  | `POST /api/activities/{id}/saves`           | `save`                            |
| `signup`| `POST /api/activities/{id}/signups`         | `signup`                          |

Weights used in recommendation scoring: `view=1`, `save=3`, `signup=5`.

---

### Activity List UI

The activity list table exposes all publish-time rule fields to every authenticated user — no role gating on read:

| Column | Contents |
|--------|----------|
| **Title** | Activity title, eligibility tags (as badges), required supplies (muted text) |
| **Status** | Coloured status badge |
| **Signups** | `current / max_headcount` (or just `current` when no cap) |
| **Signup Window** | Open and close timestamps in `MM/DD/YYYY h:mm AM/PM` format |
| **Created** | Creation timestamp in same format |
| **Actions** | Role-gated buttons (see below) |

**Eligibility tag authoring:** The **New Activity** modal and the **Edit Activity** modal (both visible to admin and ops_staff) include an **Eligibility Tags** field. Tags are entered as a comma-separated string, parsed and de-duplicated client-side via `parseTagInput()`, and submitted in the `tags` array of `POST /api/activities` (create) or `PUT /api/activities/:id` (edit). Tags are trimmed and deduplicated so `"sports, outdoor, sports"` becomes `["sports", "outdoor"]`.

**Eligibility tag display:** The API returns tags as relation objects (`{id, activity_id, tag}`). The frontend normalises each entry via `normalizeActivityTags()`: plain strings are used directly; objects yield their `.tag` field; blank and null values are discarded. This prevents `[object Object]` from appearing in the UI.

**Required supplies:** Rendered from the `required_supplies` field via `normalizeActivitySupplies()`, which accepts both an array of strings and a single comma-separated string, and silently omits empty values.

**Changelog (Log button) visibility:**
- Admin and ops_staff — visible for all activities regardless of status.
- All other roles (team_lead, reviewer, regular) — visible only on **published** activities.

**Activity edit flow:** Admin and ops_staff see an **Edit** button on every activity row. Clicking it opens a pre-populated **Edit Activity** modal with all fields (title, description, signup window, max headcount, eligibility tags, required supplies). Submitting sends `PUT /api/activities/:id`. For **published** activities the backend automatically creates a version record capturing the diff; the **Log** button then shows that version in the changelog. For **draft** activities no version is created. Regular users, reviewers, and team leads cannot call the edit endpoint (403).

Write actions (Create, Edit, Publish) remain restricted to admin/ops_staff.

---

### Dashboard Features

#### Drag-and-Drop Layout Editing

Selecting a dashboard and clicking **Edit Layout** enters drag mode. Widgets appear as draggable tiles; drag to reorder them. On mobile, up/down arrow buttons replace native drag. Clicking **Save Layout** sends `PUT /api/dashboards/:id` with an updated `layout_json` array where each entry carries a `widget_type` and `position` (`x`, `y`, `w`, `h`). Cancelling exits without saving.

#### Drill-Down Interaction

For `activity_status` and `order_pipeline` widgets, each status row is clickable. Clicking opens an inline detail panel showing up to 20 records with that status. This calls `GET /api/widgets/data?widget_type=<type>&drill_status=<status>` which returns the normal summary map plus a `drill` array of records. A **Close** button dismisses the panel and returns to the summary view.

---

## Security

- **Authentication:** JWT HS256, 7-day expiry. Secret generated at container start via `openssl rand -hex 32`.
- **Passwords:** bcrypt cost=12. Never returned in API responses.
- **Lockout:** 5 failed login attempts → 15-minute lockout.
- **RBAC:** 5 roles — admin, ops_staff, team_lead, reviewer, regular.
- **Field encryption:** AES-256-CBC via `EncryptionService`; sensitive fields masked in UI.
- **No `.env` files:** All secrets are runtime-generated in `entrypoint.sh`.
- **Exports:** All PDF/PNG/XLSX exports are watermarked with username and timestamp.

---

## Scheduled Commands

Run inside the backend container:

```bash
# Auto-cancel orders pending payment > 30 minutes
docker compose exec backend php think order:auto-cancel

# Clean up orphaned search index entries older than 7 days
docker compose exec backend php think index:cleanup

# Recompute recommendation scores (hourly via cron)
docker compose exec backend php think recommendation:recompute
```

---

## Configuration

No `.env` files are used. Runtime configuration is injected via environment variables in `docker-compose.yml`:

| Variable         | Default              | Description                        |
|------------------|----------------------|------------------------------------|
| DB_HOST          | db                   | MySQL host                         |
| DB_NAME          | campus               | Database name                      |
| DB_USER          | campus               | Database user                      |
| DB_PASSWORD      | campus               | Database password                  |
| JWT_SECRET       | (auto-generated)     | HS256 signing key (32-byte hex)    |
| ENCRYPTION_KEY   | (auto-generated)     | AES-256-CBC key (32-byte hex)      |
| APP_DEBUG        | false                | ThinkPHP debug mode                |

---

## License

Internal use only.
