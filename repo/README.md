# Campus Portal

Unified Campus Operations & Logistics Management Portal — coordinating offline events, order fulfillment, and accountability within a single on-premise site.

**Stack:** ThinkPHP 6.x (PHP 8.2) · Layui 2.x frontend · MySQL 8.0 · Docker Compose

---

## Quick Start

### Prerequisites

- Docker 24+ and Docker Compose V2 (or legacy `docker-compose`)
- No other local toolchain is required — everything runs inside containers

### 1. Clone & start

```bash
git clone <repository-url>
cd repo
docker compose up --build -d
```

The portal is available at **http://localhost:8080**

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

### 3. Run the test suite

```bash
bash run_tests.sh
```

This script:
1. Builds all Docker images
2. Starts the full stack (db, backend, nginx)
3. Seeds demo accounts
4. Runs PHPUnit against the live HTTP endpoints
5. Tears down all containers on exit

> **No mocking.** Every test sends real HTTP requests to the running ThinkPHP application. No controllers, services, or auth middleware are mocked.

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
├── frontend/           # Static Layui HTML/CSS/JS
│   ├── index.html      # Dashboard home
│   ├── pages/          # Per-feature pages
│   ├── js/             # api.js, app.js
│   └── css/campus.css  # Design-token stylesheet
├── db/migrations/      # Ordered SQL migration files (001–007)
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
| Activities    | CRUD + state, signups, versions, tasks |
| Orders        | CRUD + state, refunds, corrections     |
| Shipments     | CRUD + events, delivery, exceptions    |
| Violations    | Rules, violations, evidence, appeals   |
| Search        | Global full-text, logistics            |
| Recommendations | List + activity-detail context       |
| Dashboards    | CRUD, favorites, widget data, export   |

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
docker compose exec backend php think recommendations:recompute
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
