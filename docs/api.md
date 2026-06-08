# API Specification

REST API for the **Unified Campus Operations & Logistics Management Portal**
(ThinkPHP backend, consumed by the Layui frontend).

- **Base URL:** `http://localhost:3000` (nginx → php-fpm). All paths below are prefixed with `/api`.
- **Source of truth:** `repo/backend/route/api.php`.
- **Content type:** `application/json` for request and response bodies.

## Authentication

- Login with local username + password to obtain a JWT.
- Send it on every other request as `Authorization: Bearer <token>`.
- All routes except `POST /api/auth/login` are behind the `Auth` middleware and return **401** when the token is missing or invalid.
- Role-restricted routes additionally enforce role via middleware / controller checks and return **403** when the caller's role is insufficient. Roles: `admin`, `ops` (operations staff), `team_lead`, `reviewer`, `regular`.

## Response envelope

Every endpoint returns a consistent envelope:

```jsonc
// success
{ "code": 200, "msg": "ok", "data": { /* payload */ } }
// validation / error
{ "code": 422, "msg": "Search query must be at least 2 characters", "errors": { "q": "too short" } }
```

| Status | Meaning                                             |
|--------|-----------------------------------------------------|
| 200    | OK                                                  |
| 201    | Created                                             |
| 401    | Unauthenticated (missing/invalid token)             |
| 403    | Forbidden (authenticated but role/ownership denied) |
| 404    | Resource not found                                  |
| 422    | Validation failure (see `errors`)                   |

---

## Auth

| Method | Path               | Purpose                                  | Auth |
|--------|--------------------|------------------------------------------|------|
| POST   | `/api/auth/login`  | Authenticate; returns `{ token, user }`. | Public |
| POST   | `/api/auth/logout` | Invalidate client session (stateless).   | Any role |

## Users

| Method | Path                       | Purpose                                | Notes |
|--------|----------------------------|----------------------------------------|-------|
| GET    | `/api/users`               | List users.                            | Admin |
| POST   | `/api/users`               | Create a user.                         | Admin |
| GET    | `/api/users/:id`           | Get a single user.                     | Admin / self |
| PUT    | `/api/users/:id`           | Update a user.                         | Admin / self |
| DELETE | `/api/users/:id`           | Delete a user.                         | Admin |
| GET    | `/api/users/:id/sensitive` | Reveal masked/encrypted fields.        | Admin only |

## Activities

| Method | Path                                   | Purpose                                                       |
|--------|----------------------------------------|---------------------------------------------------------------|
| GET    | `/api/activities`                      | List activities (filters, pagination).                        |
| POST   | `/api/activities`                      | Create a draft activity.                                      |
| GET    | `/api/activities/:id`                  | Activity detail (records a view via BehaviorCapture).         |
| PUT    | `/api/activities/:id`                  | Edit; once published, creates a new version + change log.     |
| DELETE | `/api/activities/:id`                  | Delete an activity.                                           |
| PATCH  | `/api/activities/:id/state`            | State transition (Draft→Published→In Progress→Completed→Archived). |
| GET    | `/api/activities/:id/versions`         | Version history / change log.                                 |
| POST   | `/api/activities/:id/signups`          | Sign up the current user.                                     |
| DELETE | `/api/activities/:id/signups/:uid`     | Cancel a signup.                                              |
| POST   | `/api/activities/:id/saves`            | Save/bookmark the activity.                                   |
| DELETE | `/api/activities/:id/saves`            | Remove the saved bookmark.                                    |

### Tasks (per activity)

| Method | Path                                | Purpose                              |
|--------|-------------------------------------|--------------------------------------|
| GET    | `/api/activities/:id/tasks`         | List task breakdowns for an activity.|
| POST   | `/api/activities/:id/tasks`         | Create a task (team lead).           |
| PUT    | `/api/activities/:id/tasks/:tid`    | Update a task / checklist / staffing.|
| DELETE | `/api/activities/:id/tasks/:tid`    | Delete a task.                       |

## Orders

| Method | Path                                    | Purpose                                                          |
|--------|-----------------------------------------|------------------------------------------------------------------|
| GET    | `/api/orders`                           | List orders (scoped to the caller unless admin).                 |
| POST   | `/api/orders`                           | Create an order tied to an activity (ops staff).                 |
| GET    | `/api/orders/:id`                        | Order detail.                                                    |
| DELETE | `/api/orders/:id`                        | Delete an order.                                                 |
| PATCH  | `/api/orders/:id/state`                  | State machine: Placed→Pending Payment→Paid→Ticketing→Ticketed→Closed (or Canceled). |
| POST   | `/api/orders/:id/refund`                 | Refund a Paid order before Ticketed (**admin only**).            |
| POST   | `/api/orders/:id/invoice-corrections`    | Request an invoice address correction on a Closed order.         |
| PATCH  | `/api/invoice-corrections/:id/review`    | Reviewer approves/rejects a correction request.                  |

## Fulfillment / Shipments

| Method | Path                              | Purpose                                                   |
|--------|-----------------------------------|-----------------------------------------------------------|
| GET    | `/api/shipments`                  | List shipments (scoped to caller unless admin).           |
| POST   | `/api/shipments`                  | Create a shipment with package splitting (one→many).      |
| GET    | `/api/shipments/:id`              | Shipment detail incl. packages & tracking.                |
| DELETE | `/api/shipments/:id`              | Delete a shipment.                                        |
| POST   | `/api/shipments/:id/events`       | Record a local scan/tracking event.                       |
| PATCH  | `/api/shipments/:id/deliver`      | Confirm delivery.                                         |
| POST   | `/api/shipments/:id/exceptions`   | Record an exception receipt.                              |
| GET    | `/api/subscriptions`              | Get the caller's in-app alert preferences.                |
| PUT    | `/api/subscriptions`              | Update in-app alert preferences.                          |

## Violations & Demerits

| Method | Path                                        | Purpose                                            |
|--------|---------------------------------------------|----------------------------------------------------|
| GET    | `/api/violation-rules`                      | List configurable rules with point values.         |
| POST   | `/api/violation-rules`                      | Create a rule (admin).                              |
| PUT    | `/api/violation-rules/:id`                  | Update a rule.                                      |
| DELETE | `/api/violation-rules/:id`                  | Delete a rule.                                      |
| GET    | `/api/violations`                           | List violation records.                            |
| POST   | `/api/violations`                           | Record a violation/reward.                          |
| GET    | `/api/violations/:id`                       | Violation detail.                                  |
| DELETE | `/api/violations/:id`                       | Delete a violation.                                |
| POST   | `/api/violations/:id/evidence`              | Attach evidence (JPG/PNG/PDF ≤10MB, SHA-256).      |
| POST   | `/api/violations/:id/appeals`               | File an appeal.                                    |
| PATCH  | `/api/violations/:id/appeals/review`        | Reviewer decision (notes required).                |
| PATCH  | `/api/violations/:id/appeals/re-review`     | Re-review decision (notes required).               |
| GET    | `/api/point-summary/users/:uid`             | Aggregated points for an individual.               |
| GET    | `/api/point-summary/groups/:gid`            | Aggregated points for a group.                     |

## Search

| Method | Path                     | Purpose                                                                 |
|--------|--------------------------|-------------------------------------------------------------------------|
| GET    | `/api/search`            | Global full-text search w/ highlighting across title, body, author, tags. |
| GET    | `/api/search/logistics`  | Order/logistics search: tokenization, optional `use_synonyms` & `use_pinyin`, spell correction. |

Common query params: `q` (≥2 chars), `sort` (`recency`/`relevance`/`popularity`/`reply_count`), `entity_type`, `page`, `per_page`, `use_synonyms`, `use_pinyin`.

## Recommendations

| Method | Path                                       | Purpose                                              |
|--------|--------------------------------------------|------------------------------------------------------|
| GET    | `/api/recommendations`                     | Feed recommendations (cold-start by top tags, diversity-capped). |
| GET    | `/api/recommendations/activities/:id`      | Related activities for a detail page.                |
| GET    | `/api/recommendations/orders/:id`          | Related orders for a detail page.                    |

## Dashboards

| Method | Path                              | Purpose                                          |
|--------|-----------------------------------|--------------------------------------------------|
| GET    | `/api/dashboards`                 | List dashboards.                                 |
| POST   | `/api/dashboards`                 | Create a custom dashboard.                       |
| GET    | `/api/dashboards/:id`             | Dashboard detail (widget layout).                |
| PUT    | `/api/dashboards/:id`             | Update layout / drag-and-drop widget config.     |
| DELETE | `/api/dashboards/:id`             | Delete a dashboard.                              |
| POST   | `/api/dashboards/:id/favorite`    | Favorite a dashboard view.                       |
| DELETE | `/api/dashboards/:id/favorite`    | Remove from favorites.                           |
| POST   | `/api/dashboards/:id/export`      | Export a snapshot to PNG/PDF/Excel (watermarked).|
| GET    | `/api/widgets/data`               | Fetch data for a widget/chart.                   |
