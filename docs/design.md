# Design Document — Campus Portal

**Project:** Unified Campus Operations & Logistics Management Portal  
**Stack:** ThinkPHP 6.x · PHP 8.2 · MySQL 8.0 · Layui 2.x · Docker Compose  
**Date:** 2026-05-22

---

## 1. System Overview

The Campus Portal is a fully offline, on-premise web application that coordinates campus events, equipment orders, fulfillment logistics, and accountability workflows. It serves five distinct roles through a unified Layui-based UI backed by a ThinkPHP REST API and a single MySQL database. All computation happens within the Docker Compose deployment — no external services, APIs, or network calls are required.

### Core capabilities

| Domain | Description |
|--------|-------------|
| Activity Management | Publish events with eligibility gating, signup windows, headcount limits; versioned change log |
| Order Lifecycle | State-machine-enforced order flow with 30-minute auto-cancel and refund controls |
| Fulfillment | Multi-package shipment tracking with offline scan events and delivery confirmation |
| Violation System | Demerit rules engine with evidence attachments, appeals, and threshold alerts |
| Search | Global full-text + logistics search with synonym expansion and pinyin support |
| Recommendations | Behavior-signal scoring with cold-start fallback and 40% tag-diversity cap |
| Dashboards | Drag-and-drop widget layouts with PNG/PDF/XLSX export and watermarking |

---

## 2. Architecture

### 2.1 Deployment topology

```
Client Browser
      │ HTTP :8080
      ▼
┌─────────────────┐
│  nginx:1.25     │  Static files: /var/www/frontend → repo/frontend/
│                 │  API proxy:    /api → backend:9000 (FastCGI)
└────────┬────────┘
         │ FastCGI (PHP-FPM)
         ▼
┌─────────────────┐
│  backend        │  PHP 8.2-fpm + ThinkPHP 6.1
│  (php-fpm)      │  Secrets generated in entrypoint.sh at start
│                 │  Uploads stored in public/uploads/
└────────┬────────┘
         │ TCP :3306
         ▼
┌─────────────────┐
│  db (MySQL 8.0) │  utf8mb4_unicode_ci; migrations auto-applied via
│                 │  /docker-entrypoint-initdb.d/
└─────────────────┘
```

### 2.2 Request lifecycle

1. Browser sends HTTP request to nginx `:8080`.
2. nginx routes `/api/*` to ThinkPHP via FastCGI; all other paths serve static files from `frontend/`.
3. ThinkPHP router (route/api.php) dispatches to the Auth middleware, then the controller.
4. The `Auth` middleware validates the JWT from `Authorization: Bearer <token>` and injects `user_id`, `user_role`, `username` onto the request object.
5. The controller validates input, calls one or more service classes, and returns a JSON response `{ "code": 0, "data": … }`.
6. The global exception handler (`Handle.php`) maps exception types to HTTP status codes without leaking internals.

---

## 3. Database Schema

Seven ordered migration files produce the full schema:

| File | Tables |
|------|--------|
| 001_initial_schema | users, audit_log, user_tags |
| 002_activities | activities, activity_versions, activity_tags, activity_signups |
| 003_orders_fulfillment | orders, order_refunds, invoice_corrections, shipments, shipment_packages, shipment_events, user_subscriptions |
| 004_tasks_violations | activity_tasks, violation_rules, violations, violation_evidence, violation_appeals, user_point_cache, group_point_cache, notifications |
| 005_search_indexes | search_index (FULLTEXT), logistics_index (FULLTEXT), index_orphan_candidates, synonym_map |
| 006_recommendations | behavior_events, recommendation_cache, tag_popularity |
| 007_dashboard_security | dashboard_layouts, dashboard_favorites, dashboard_exports; encrypted columns on orders and users |

**Encoding:** All tables use `utf8mb4_unicode_ci` to support Chinese characters and emoji.

**Encryption at rest:** `orders.invoice_contact_enc`, `orders.invoice_address_enc`, and `users.passenger_id_enc` store AES-256-CBC ciphertext (IV prepended, base64-encoded). The decryption key is never in version control — it is generated at runtime by `entrypoint.sh`.

---

## 4. Authentication & Authorization

### 4.1 Authentication flow

- Login: `POST /api/auth/login` validates credentials against `users.password_hash` (bcrypt cost=12).
- Lockout: 5 consecutive failures set `locked_until = NOW() + 15 minutes`. The lockout check happens before the password compare.
- Timing attack mitigation: a dummy `password_verify()` is called for unknown usernames to equalize response time.
- On success: a JWT (HS256, 7-day expiry, signed with `JWT_SECRET`) is returned. The frontend stores the token in `localStorage`.
- Logout: `POST /api/auth/logout` is recorded; the token is single-use from a trust perspective (stateless).

### 4.2 Role-based access control

Five roles with progressively broader access:

| Role | Key Permissions |
|------|----------------|
| regular | Browse published activities, sign up, view own violations |
| team_lead | All of regular + manage tasks for own team, view team violation points |
| reviewer | All of regular + review invoice corrections, review appeals |
| ops_staff | All of regular + create/manage orders, log violations, manage shipments |
| admin | Full access to all resources and admin-only operations |

Role checks are enforced at the controller layer via explicit `$request->user_role` guards, not middleware policy objects, to keep the authorization logic co-located with the business logic.

---

## 5. Activity State Machine

```
draft ──────────────────── published
  │                            │
  │                      in_progress
  │                            │
  └──────────── ◄──── completed │ archived
```

Allowed transitions (enforced in `ActivityService::TRANSITIONS`):

| From | To | Permitted roles |
|------|----|-----------------|
| draft | published | ops_staff, admin |
| published | in_progress | ops_staff, admin |
| published | archived | admin |
| in_progress | completed | ops_staff, admin |
| completed | archived | admin |

Post-publish edits create a new row in `activity_versions` with a `diff_json` field storing field-level before/after values. The frontend renders the diff with `<mark>` highlights.

---

## 6. Order State Machine

```
placed → pending_payment → paid → ticketing → ticketed → closed
                 │                   │
                 ▼                   ▼
              canceled (30min)   refund (admin)
```

Transitions are enforced by `OrderService::TRANSITIONS`. Key rules:

- `pending_payment` auto-cancels after 30 minutes via the `order:auto-cancel` scheduled command.
- `paid → refund` is available to administrators only, before the order reaches `ticketed`.
- `closed` records are immutable except for invoice address corrections, which require a reviewer decision recorded in `invoice_corrections`.
- All state changes are wrapped in `SELECT ... FOR UPDATE` transactions to prevent concurrent state corruption.

---

## 7. Search Architecture

### 7.1 Global full-text search

- Index table: `search_index` with a MySQL `FULLTEXT(title, body, author_name, tags)` index.
- Query: `MATCH(title, body, author_name, tags) AGAINST('+term1* +term2*' IN BOOLEAN MODE)`.
- Highlights: server-side `preg_replace` wraps matched terms in `<mark>` tags.
- Index maintenance: `IndexObserver` hooks `afterSave`/`afterDelete` on models; a nightly `index:cleanup` command removes orphaned entries older than 7 days.
- Role scoping: regular users only see `published` and later activities.

### 7.2 Logistics search

- Index table: `logistics_index` with `FULLTEXT(display_name, pinyin_name)`.
- Tokenization: query split on whitespace and punctuation.
- Spell correction: Levenshtein distance ≤ 2 against synonym map tokens.
- Synonym expansion: loaded from `synonym_map` table, seeded with common aliases.
- Pinyin: `pinyin_name` column stores a transliteration of the display name. Queries hit both columns via OR.

---

## 8. Recommendation Engine

**Compute cycle:** Hourly via the `recommendations:recompute` scheduled command. Results are cached in `recommendation_cache` keyed by `(user_id, context)`.

**Scoring pipeline (8 steps):**

1. Cache check — return cached result if age < 1 hour.
2. Load user signal vector (view=1, save=3, signup=5 weights, normalized to [0,1]).
3. Cold-start fallback for users with no signals — use `tag_popularity` (top tags in last 30 days).
4. Score candidate activities: tag affinity × recency decay × popularity.
5. Sort by score descending.
6. Deduplicate within order families (same activity group).
7. Apply 40% tag diversity cap: no single tag may represent more than 40% of a feed page (greedy selection).
8. Write to `recommendation_cache`.

**Behavior capture:** The `BehaviorCapture` middleware intercepts `GET /api/activities/{id}` responses with HTTP 200 and inserts a `view` event (with 30-minute deduplication per user/activity pair).

---

## 9. Violation & Demerit System

**Rules engine:** Configurable `violation_rules` table with `points` (positive = reward, negative = demerit), a description, and metadata.

**Point aggregation:**
- Individual points: maintained in `user_point_cache` via race-safe upsert (`INSERT ... ON DUPLICATE KEY UPDATE total_points = total_points + delta`).
- Group points: `group_point_cache` aggregates by `team_id`.
- Thresholds: on every point change, `ViolationService::checkThresholds()` fires notifications at 25 (manager review) and 50 (administrative action) points.

**Evidence:** Files (JPG/PNG/PDF, max 10 MB each) are validated by MIME type and SHA-256 fingerprinted on upload. Fingerprints are stored in `violation_evidence.sha256` for deduplication detection.

**Appeals:** A violation owner submits an appeal with a reason. A reviewer approves or rejects with required decision notes. Approval reverses the original point delta by recording a new violation record with negated points.

---

## 10. Dashboard & Export System

**Layouts:** Stored as a JSON array of widget descriptors in `dashboard_layouts.layout_json` per user.

**Widget types:**
- `activity_status` — activity counts by status
- `order_pipeline` — order counts by state
- `violation_leaderboard` — top-10 users by demerit points
- `fulfillment_rate` — delivered / total shipments in last 30 days

**Exports** (all watermarked with username + MM/DD/YYYY 12-hour timestamp):
- **PDF:** mPDF with watermark text layer
- **XLSX:** PhpSpreadsheet with watermark cell in row 2
- **PNG:** GD library with text watermark and diagonal alpha overlay

---

## 11. Security

| Concern | Implementation |
|---------|----------------|
| Password storage | bcrypt cost=12 via `password_hash(PASSWORD_BCRYPT)` |
| JWT signing | HS256, 7-day expiry, `JWT_SECRET` from env |
| Field encryption | AES-256-CBC, IV prepended to ciphertext, base64-encoded |
| UI masking | `EncryptionService::mask()` shows only last 4 chars |
| Login lockout | 5 attempts → 15-minute lockout in `users.locked_until` |
| No `.env` files | `JWT_SECRET` and `ENCRYPTION_KEY` generated at container start |
| Export watermarking | Username + timestamp on all PDF/PNG/XLSX exports |
| RBAC | Enforced per-action in controllers via `$request->user_role` |

---

## 12. Assumptions & Design Decisions

1. **Payment is manual:** No payment gateway. An authorized staff member manually transitions an order from `pending_payment` to `paid`. `payment_reference` free-text field stores receipts.
2. **Eligibility tags are admin-assigned:** Stored in `user_tags` pivot table. Activity signup validation uses a single `EXISTS` query.
3. **Activity versioning is append-only:** Rollback is supported by setting `current_version_id` to an older version, which creates a new version record with `action=rollback`.
4. **Recommendations are hourly pre-computed:** A 1-hour staleness window is acceptable. Real-time signal writes happen immediately but only influence rankings at the next compute cycle.
5. **Invoice corrections are single-reviewer:** No secondary administrator sign-off. Reviewer decision is final.
6. **Scan events are manual text entry:** Hardware scanner support is not required — the text input field is compatible with keyboard-wedge barcode scanners without code changes.
7. **Group = team:** Demerit group aggregation uses the existing team structure.
8. **Dashboard layouts are personal:** No shared template feature in this release.
