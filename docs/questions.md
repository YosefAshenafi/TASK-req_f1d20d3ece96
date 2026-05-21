# Clarification Questions

---

## Question 1

### Question
The order lifecycle includes "Pending Payment" and "Paid" states, and the prompt mentions refunds — yet the system is described as fully offline with no external services. Is payment tracking purely a manual status update (a staff member marks an order as Paid after collecting cash or a receipt), or is there an actual payment capture flow that must be modeled (e.g., integration with an on-premise POS terminal or bank transfer reference)?

### Assumption
Payment state transitions are manual: an authorized staff member changes the status from Pending Payment to Paid after verifying payment offline. No payment gateway or POS integration is required.

### Suggested Solution
Implement payment state as a privileged manual transition available only to Operations Staff and Administrators. Store a `payment_reference` free-text field for receipt or transfer IDs. The 30-minute auto-cancel timer runs as a scheduled ThinkPHP command (cron). Refunds are recorded as a separate `refund` ledger row linked to the order, triggered only by Administrator action before the Ticketed state.

---

## Question 2

### Question
The logistics/order search explicitly mentions "pinyin matching for names." Does this confirm the system must support Chinese-language user and entity names as a first-class requirement? If so, what is the expected character encoding for the MySQL schema, and are any other locale-specific features (UI language switching, date/time locale, Chinese collation for sorting) required?

### Assumption
Pinyin matching is an optional enhancement for Chinese names stored in an otherwise English-primary system. The database uses `utf8mb4` encoding. No UI language switching is required; all interface text is in a single language (Chinese or English, to be confirmed).

### Suggested Solution
Set the MySQL schema to `utf8mb4_unicode_ci` throughout. Build a lightweight pinyin index table that stores a pinyin transliteration of each name field alongside the original; the search layer queries both columns with an OR condition. Pinyin generation runs locally via a PHP library (no external API). If full bilingual UI is needed, use a JSON-based i18n file loaded by Layui templates.

---

## Question 3

### Question
Activity eligibility tags gate which Regular Users can sign up. How are eligibility tags assigned to users — are they self-declared, assigned by an Administrator, derived from group/department membership, or imported from an external roster? Can a single user belong to multiple eligibility groups simultaneously?

### Assumption
Eligibility tags are administrator-assigned to user accounts (stored as a many-to-many relationship). A user may hold multiple tags. At signup time the system checks that the user possesses at least one of the tags required by the activity.

### Suggested Solution
Create a `user_tags` pivot table (`user_id`, `tag_id`, `assigned_by`, `assigned_at`). Activity publish form allows selecting one or more required tags (empty = open to all). Signup validation is a single SQL EXISTS query against the pivot. Administrators can bulk-assign tags via a CSV import on the user management page.

---

## Question 4

### Question
The violation/demerit system aggregates points "per individual and per group." How is a group defined in this context — is it the same as a team managed by a Team Lead, a department, a custom cohort, or something else? And when a group threshold (25 or 50 points) is crossed, which group members trigger the alert — only the member who pushed it over, or all members?

### Assumption
A group corresponds to a Team Lead's team (the existing team entity). Group-level points are the sum of all individual members' points within that team. When a threshold is crossed, the alert is sent to the Team Lead and the relevant Administrator; no individual member receives the threshold alert (only their own point history is visible to them).

### Suggested Solution
Add a `team_id` foreign key to the `violation_records` table. Maintain a `team_point_cache` table updated via a trigger or service layer call on every point change. Alert records are written to a `notifications` table with `recipient_role` targeting Team Lead and Administrator; the UI polls this table on page load and via a lightweight interval for in-app display.

---

## Question 5

### Question
Activity versioning creates a new version on every post-publish edit. Are all historical versions permanently retained and searchable, or only the current version appears in search results while older versions are archived for audit viewing only? Can a reviewer or administrator roll back to a previous version?

### Assumption
Only the current (latest) version is indexed and returned by search. Older versions are stored in an `activity_versions` table for audit viewing and change-log display but are not searchable. Rollback by Administrators is supported, which promotes a previous version to current and creates a new version entry recording the rollback event.

### Suggested Solution
Store the canonical activity in `activities` with a `current_version_id` foreign key. Each edit appends a row to `activity_versions` (full snapshot + diff JSON). The search index keys on `activity_id` and is rebuilt whenever `current_version_id` changes. The change-log UI renders the diff JSON with field-level highlights. Rollback sets `current_version_id` and writes a version record with `action = rollback`.

---

## Question 6

### Question
The recommendation engine uses "local behavior signals (views, saves, signups, and tags)" and enforces a 40% diversity cap per tag per feed page. Should recommendations be computed in real time per request, or pre-computed on a schedule and cached? What is the acceptable staleness window for recommendation results, and is there a performance budget (e.g., max response time) for list pages that include a recommendation panel?

### Assumption
Recommendations are pre-computed hourly and cached in a MySQL summary table. Real-time signal updates (view counts, signup counts) are written immediately but influence recommendation rankings only at the next compute cycle. A 1-hour staleness window is acceptable. List page total response time target is under 500 ms.

### Suggested Solution
Run a ThinkPHP scheduled command every hour that scores activities per user segment (tag affinity × recency × popularity), applies the 40% tag cap via a greedy selection pass, and writes results to a `recommendation_cache` table keyed by `(user_id, context, computed_at)`. Cold-start users (no behavior history) receive the top-performing-tag default feed computed from the same job. List-page API reads from the cache table with a single indexed lookup.

---

## Question 7

### Question
The prompt states Closed order records are "immutable except for invoice address corrections with reviewer approval." What exactly constitutes an invoice address correction — a free-text address field update only, or can it include contact name, tax ID, or other billing fields? And does the approval workflow require a two-step confirm (reviewer proposes, administrator approves) or a single reviewer decision?

### Assumption
An invoice address correction covers the address, contact name, and any associated billing reference fields but not order items, amounts, or fulfillment data. Approval is a single-step reviewer decision with required notes; no secondary administrator sign-off is needed.

### Suggested Solution
Define an `invoice_correction_requests` table with fields for the target order, the changed field set (JSON patch), requester, reviewer, decision (`approved`/`rejected`), and decision notes. Only Reviewers can act on the request. On approval, the system applies the patch to the order's invoice fields and appends an immutable audit row. All other order fields remain locked at the database level via an application-layer guard that checks `status = closed AND field NOT IN (invoice_fields)`.

---

## Question 8

### Question
The fulfillment module supports package splitting (one order to multiple packages) and offline tracking via locally entered scan events. Who is responsible for entering scan events — warehouse staff using a dedicated scan entry form, or any Operations Staff member? Is there a barcode/QR scanning UI (camera or hardware scanner input), or is manual text entry the only method?

### Assumption
Any Operations Staff member with fulfillment permission can enter scan events through a manual text-entry form. No camera or hardware barcode scanner integration is required for the initial release.

### Suggested Solution
Build a `shipment_events` table (`shipment_id`, `event_type`, `location`, `scanned_at`, `entered_by`). The UI provides a simple form with a tracking number lookup (which resolves to one or more packages), an event type dropdown (e.g., Dispatched, In Transit, Delivered, Exception), and a timestamp field defaulting to now. If hardware scanner support is added later, the tracking number input field can accept barcode keyboard-wedge output without code changes, since it is a standard text input.

---

## Question 9

### Question
The custom dashboard feature allows Managers to drag-and-drop widgets, drill into linked charts, and favorite views. What is the full intended set of widget types (e.g., activity status summary, order pipeline funnel, violation leaderboard, fulfillment SLA gauge)? Are dashboard layouts personal (per user) only, or can a Manager publish a layout as a shared template for other Managers to adopt?

### Assumption
Widgets cover at minimum: activity status counts, order pipeline by state, violation points leaderboard, and fulfillment delivery rate. Dashboard layouts are personal (per Manager) in the initial release; shared templates are not required.

### Suggested Solution
Store layouts in a `dashboard_layouts` table as a JSON column (`user_id`, `layout_json`, `updated_at`). Each widget descriptor in the JSON references a `widget_type` slug and a filter configuration object. The backend exposes a `/api/dashboard/data?widget=<type>&filters=<json>` endpoint that each widget calls independently, enabling lazy loading. Drill-through links embed the filter state as URL query parameters so the destination report page opens pre-filtered. PNG/PDF/Excel export serializes the current widget data via a server-side render triggered by the frontend.

---

## Question 10

### Question
The prompt specifies salted password hashing and encryption at rest for sensitive fields, but does not name the hashing algorithm or encryption scheme. What cryptographic standards are required or preferred — e.g., bcrypt/Argon2 for passwords and AES-256 for field-level encryption? Are there any compliance frameworks (internal policy, ISO 27001, or similar) that mandate specific algorithms or key management practices?

### Assumption
Passwords are hashed with bcrypt (cost factor 12). Sensitive fields (passenger identifiers, invoice contacts) are encrypted with AES-256-CBC using a single application-level key stored in the `.env` file on the server. No formal compliance framework mandates a specific standard beyond the prompt's own requirements.

### Suggested Solution
Use PHP's `password_hash(PASSWORD_BCRYPT, ['cost' => 12])` for all passwords. For field-level encryption, implement a thin `EncryptedCast` in ThinkPHP's model layer using `openssl_encrypt`/`openssl_decrypt` with AES-256-CBC and a per-environment key loaded from `.env` (never committed to version control). Store the IV alongside the ciphertext as a prefixed base64 string. Document key rotation procedure in `docs/design.md` since there is no external KMS.
