# Prompt Requirements Verification

## 1) Prompt Requirements Extracted from `metadata.json`
Authoritative source: `metadata.json` → `prompt`.

Extracted requirement groups:
1. Unified on-prem campus operations/logistics portal using Layui frontend, ThinkPHP REST backend, MySQL persistence.
2. Role-based navigation and permissions for `admin`, `ops_staff`, `team_lead`, `reviewer`, `regular`.
3. Activities: browse/signup with publish-time rules (signup window, max headcount, eligibility tags, required supplies), lifecycle states with visible timestamps in `MM/DD/YYYY` 12-hour format.
4. Published activity edits create new versions with highlighted changelog.
5. Orders tied to activities and strict lifecycle state machine.
6. Pending-payment auto-cancel after 30 minutes.
7. Refunds only by admin before ticketed.
8. Closed orders immutable except invoice **address** correction with reviewer approval.
9. Fulfillment: shipments with package splitting, carrier/tracking, local scan events, delivery confirmation, exceptions, in-app reminders, local subscription preferences.
10. Team lead task management with breakdown/staffing/checklists.
11. Global full-text search + logistics search (tokenization, optional synonym/pinyin, spell correction, filters/sorts).
12. Recommendations from local behavior signals with cold-start top tags (30 days), dedup by family, 40% tag diversity cap, list/detail surfaces.
13. Custom dashboards with drag-drop widgets, drill-down, favorites, exports to PNG/PDF/Excel.
14. Violation system: configurable rules, evidence upload JPG/PNG/PDF <=10MB + SHA-256, appeal + re-review with required decision notes, user/group point aggregation and threshold alerts at 25/50.
15. Security: local login/password min 10 chars, lockout after 5 failures for 15 min, salted hashing, RBAC, masking + at-rest encryption for sensitive fields, watermarked exports.
16. Local incremental indexing on C/U/D and cleanup of orphaned index entries older than 7 days.

---

## 2) Requirement-by-Requirement Status with Evidence

### R1. Stack and architecture
- **Status:** implemented
- **Evidence:**
  - `repo/backend/composer.json:7` (`topthink/framework`)
  - `repo/frontend/vendor/layui/css/layui.min.css` (Layui asset present)
  - `repo/backend/route/api.php:7-113` (REST endpoints)
- **Why:** Required frontend/backend/database architecture is directly represented.

### R2. On-prem/offline single-site behavior
- **Status:** likely implemented
- **Evidence:**
  - `repo/frontend/js/api.js:6` (`BASE = '/api'` same-origin local API)
  - `repo/frontend/vendor/layui/...` local vendored assets
- **Why:** Code is structured for local self-hosted operation without external runtime dependencies.

### R3. Role-based navigation and role model
- **Status:** implemented
- **Evidence:**
  - `repo/frontend/js/app.js:27-30` role labels for all required roles
  - `repo/frontend/js/app.js:58-68` role-gated nav items
  - `repo/db/migrations/001_initial_schema.sql:8` role enum includes all five roles
- **Why:** Roles are enforced in model/schema and frontend navigation.

### R4. Activities: publish-time rules + signup behavior
- **Status:** implemented
- **Evidence:**
  - `repo/frontend/pages/activities.html:285-299` shows status, signup counts, signup window
  - `repo/frontend/pages/activities.html:292-295` renders eligibility tags and required supplies
  - `repo/backend/app/service/ActivityService.php:158-181` signup window/headcount/eligibility enforcement
- **Why:** Both display and backend checks are present.

### R5. Activity lifecycle states + transition enforcement
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/ActivityService.php:20-26` transition map
  - `repo/backend/app/controller/Activity.php:94-97` allowed states validation
  - `repo/backend/app/controller/Activity.php:106-113` timestamp fields returned by transition
- **Why:** Required state machine exists and is enforced.

### R6. Timestamp format requirement
- **Status:** implemented
- **Evidence:**
  - `repo/frontend/js/app.js:43-55` `MM/DD/YYYY h:mm AM/PM` formatter
  - `repo/frontend/pages/activities.html:298-299,353` uses `fmt(...)` for visible times
- **Why:** UI format matches prompt.

### R7. Published edit versioning + highlighted changelog
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/ActivityService.php:84-109` version creation with diff for published/in_progress/completed
  - `repo/frontend/pages/activities.html:349-373` changelog with diff and `<mark>` highlight
- **Why:** Required version/changelog behavior is directly implemented.

### R8. Orders tied to activities + lifecycle
- **Status:** likely implemented
- **Evidence:**
  - `repo/db/migrations/003_orders_fulfillment.sql:6,28` `activity_id` FK in orders
  - `repo/db/migrations/003_orders_fulfillment.sql:10` required status enum
  - `repo/backend/app/service/OrderService.php:17-25` strict transition graph
- **Why:** Data model and lifecycle rules align with requirement.

### R9. Pending payment auto-cancel after 30 minutes
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/OrderService.php:70-73` 1800-second cutoff
  - `repo/backend/app/command/AutoCancelOrders.php:15-23` command executes auto-cancel
- **Why:** Exact policy is coded.

### R10. Admin-only refund before ticketed
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/OrderService.php:91-93` admin-only refund
  - `repo/backend/app/service/OrderService.php:98-100` only in `paid` state
- **Why:** `paid` gate prevents refund after ticketed, matching prompt.

### R11. Closed immutable except invoice address correction with reviewer approval
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/OrderService.php:119-127` rejects `invoice_contact`, allows only `invoice_address`
  - `repo/backend/app/service/OrderService.php:133-140` stores scoped address-only correction patch
  - `repo/backend/app/controller/Order.php:159-161` reviewer-only correction review endpoint
  - `repo/db/migrations/014_correction_scope_and_appeal_reviews.sql:13-20` correction scope formalized as `invoice_address`
- **Why:** Previous contradiction is resolved; behavior now matches prompt scope.

### R12. Fulfillment lifecycle capabilities
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/FulfillmentService.php:21-40` shipment + multi-package creation
  - `repo/backend/app/service/FulfillmentService.php:43-67` local scan events
  - `repo/backend/app/service/FulfillmentService.php:69-84` delivery/exception
  - `repo/backend/app/service/FulfillmentService.php:113-128` arrival reminder notifications
  - `repo/backend/app/service/FulfillmentService.php:97-111` local subscription preferences
- **Why:** Required fulfillment features are represented end-to-end.

### R13. Team lead task breakdown/staffing/checklists
- **Status:** implemented
- **Evidence:**
  - `repo/db/migrations/004_tasks_violations.sql:9-12` staffing/checklist fields
  - `repo/backend/app/controller/Task.php:22-44` team lead/admin task creation with staffing/checklist
- **Why:** Task domain matches prompt semantics.

### R14. Search requirements (global + logistics)
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/SearchIndexService.php:159-160` full-text query fields include title/body/author/tags_text
  - `repo/backend/app/service/SearchIndexService.php:197-204` highlights generated
  - `repo/backend/app/service/SearchIndexService.php:215-239` tokenization + synonym expansion
  - `repo/backend/app/service/SearchIndexService.php:392-406` basic spell correction
  - `repo/backend/app/service/SearchIndexService.php:247-250,412-423` optional pinyin path
  - `repo/backend/app/controller/Search.php:22-31,49-60` filter/sort validation and pagination
- **Why:** Prompted search behaviors are directly reflected.

### R15. Recommendation behavior
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/BehaviorTracker.php:10` signal weights
  - `repo/backend/app/service/RecommendationEngine.php:105-113` 30-day cold-start top tags
  - `repo/backend/app/service/RecommendationEngine.php:87-94` dedup by entity/family
  - `repo/backend/app/service/RecommendationEngine.php:11,276-303` 40% diversity cap
  - `repo/backend/app/controller/Recommendation.php:13-31` list + detail endpoints
- **Why:** Logic aligns with required recommendation rules.

### R16. Dashboard builder and exports
- **Status:** implemented
- **Evidence:**
  - `repo/frontend/pages/dashboards.html:352-420` drag-drop layout editing
  - `repo/frontend/pages/dashboards.html:314-343` drill-down interaction
  - `repo/backend/app/controller/Dashboard.php:112-145` favorites API
  - `repo/backend/app/controller/Dashboard.php:166-214` export API supports png/pdf/xlsx
- **Why:** Required dashboard capabilities are implemented.

### R17. Violation/demerit workflow including re-review
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/service/ViolationService.php:22-25,95-116` file validation (JPG/PNG/PDF <=10MB) + SHA-256
  - `repo/backend/app/service/ViolationService.php:55-71,81-87` point aggregation + 25/50 alerts
  - `repo/backend/app/service/ViolationService.php:162-173` review with required decision notes
  - `repo/backend/app/service/ViolationService.php:188-219` explicit re-review flow
  - `repo/db/migrations/014_correction_scope_and_appeal_reviews.sql:22-33` re-review history table
  - `repo/backend/route/api.php:81` re-review endpoint route
- **Why:** Appeal and re-review requirements are now represented explicitly.

### R18. Security controls
- **Status:** implemented
- **Evidence:**
  - `repo/backend/app/validate/AuthValidate.php:11` password min length 10
  - `repo/backend/app/controller/Auth.php:41-45` lockout at 5 failures for 15 minutes
  - `repo/backend/app/command/SeedDatabase.php:29` bcrypt hashing
  - `repo/backend/app/service/EncryptionService.php:24-32` encryption at rest support
  - `repo/backend/app/controller/Order.php:57-60` masking for non-admin responses
  - `repo/backend/app/service/ExportService.php:22-25,53-55,75,115` user+timestamp watermark in exports
- **Why:** Security features required by prompt are present.

### R19. Incremental indexing + orphan cleanup older than 7 days
- **Status:** likely implemented
- **Evidence:**
  - `repo/backend/app/service/ActivityService.php:221-227`, `repo/backend/app/controller/Order.php:100,128`, `repo/backend/app/controller/Fulfillment.php:51,90` index updates on create/update/transition flows
  - `repo/backend/app/service/SearchIndexService.php:136-147` delete queue for orphan candidates
  - `repo/backend/app/command/CleanupIndex.php:20-27,41` cleanup cutoff at 7 days
- **Why:** Required mechanisms exist; exact nightly scheduler is not visible in repo but cleanup job logic is present.

---

## 3) Final Verdict

**PASS**

All important prompt requirements are implemented or likely implemented, with no remaining clear major contradiction in current code evidence.
