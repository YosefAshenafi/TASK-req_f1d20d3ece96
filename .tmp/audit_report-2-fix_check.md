Verdict: PASS

# Fix-Check Report — Campus Portal (Round 2)
**Based on:** delivery_acceptance_architecture_audit_round12.md (Medium issue: tags UI authoring gap)
**Date:** 2026-05-22
**Scope:** Verifies all remaining issues from the round-12 audit are resolved in current code. Builds on audit_report-1-fix_check.md which already addressed tag rendering, supplies normalization, logistics auth, fmt(), and API tests.

---

## Issue 1 (Medium) — Activity create/edit UI lacks explicit eligibility-tag input and edit flow

**Original finding:**
> "Activity create/edit UI lacks explicit eligibility-tag input despite backend support."
> Evidence: `repo/frontend/pages/activities.html:75-117` (create modal omits tags); backend supports tags via `ActivityService.php:58-63`.

**Fix applied — Edit modal:**
Added a complete **Edit Activity** Layui modal (`id="edit-modal-body"`) with all required fields: title, description, signup start, signup end, max headcount, eligibility tags, required supplies. The modal is pre-populated when opened from the activity row.

**Fix applied — Edit button:**
Added an **Edit** button (`layui-btn-warm`) to every activity row for admin/ops_staff (`canEdit` guard). The onclick calls `openEditModal(_activityMap[a.id])`. `_activityMap` is populated by `renderTable` on every list load, so the button always has the latest fetched activity object.

**Fix applied — Data helpers:**
- `fmtDatetimeLocal(str)` — Converts `"YYYY-MM-DD HH:MM:SS"` to `"YYYY-MM-DDTHH:MM"` for the `datetime-local` input value attribute. Handles null/undefined → empty string safely.
- `openEditModal(a)` — Populates all form fields using existing `normalizeActivityTags` and `normalizeActivitySupplies` helpers to convert backend relation payloads back into comma-separated display strings.
- `closeEditModal()` — Closes the Layui layer.
- Edit form `submit` handler — Reads all fields, uses `parseTagInput` and `normalizeActivitySupplies` for normalization, and calls `API.put('/activities/:id', body)`. On success, closes modal and refreshes list.

**Evidence (current code):**
- `repo/frontend/pages/activities.html:133-184` — Edit modal HTML (7 fields + hidden id)
- `repo/frontend/pages/activities.html:191-193` — `_editModalIdx` and `_activityMap` declarations
- `repo/frontend/pages/activities.html:269-274` — `fmtDatetimeLocal()` definition
- `repo/frontend/pages/activities.html:281` — `rows.forEach(a => { _activityMap[a.id] = a; })` in `renderTable`
- `repo/frontend/pages/activities.html:302` — Edit button in action column
- `repo/frontend/pages/activities.html:387-430` — `openEditModal`, `closeEditModal`, edit form submit handler
- Status: **VERIFIED**

---

## Post-Publish Versioning — Behavior Preserved

**Requirement:** Editing a published activity must continue to trigger version creation through existing backend service behavior (`ActivityService.php`).

**Verification:** No changes were made to backend code. The edit form submits `PUT /api/activities/:id` identically to how any other client would. The backend's `ActivityService::update()` creates a version record whenever a published activity is modified — this is unchanged.

**Evidence:**
- `repo/backend/app/service/ActivityService.php` — untouched in this session
- `repo/frontend/pages/activities.html:412-426` — Edit form submit calls `API.put('/activities/${id}', body)` with no bypasses
- Status: **VERIFIED** (by code absence — no backend changes)

---

## New Tests — API (ActivityTest.php)

### `testUpdatePublishedActivity_withTagsAndSupplies_versionContainsDiff`
Creates activity → publishes → PUTs with new title, tags array, required_supplies array → asserts 200 → GETs `/versions` → asserts versions non-empty and diff includes `title` key.

**Evidence:** `repo/tests/api/ActivityTest.php:420-448`
Status: **VERIFIED**

### `testUpdateDraftActivity_noVersionCreated`
Creates activity (stays draft) → PUTs new title → asserts 200 → GETs `/versions` → asserts empty versions array (draft edits don't create versions per current backend semantics).

**Evidence:** `repo/tests/api/ActivityTest.php:450-473`
Status: **VERIFIED**

---

## New Tests — E2E (ActivityTest.php — real HTTP through full stack)

### `testEditActivity_opsStaff_returns200`
Full end-to-end flow: creates activity as ops_staff → PUTs with title, tags, supplies → asserts 200 and response `code === 200`. Validates the edit action is available and functional for authorized roles via the same HTTP path the UI uses.

**Evidence:** `repo/tests/api/ActivityTest.php:475-494`
Status: **VERIFIED**

### `testEditActivity_regularUser_returns403`
Creates and publishes activity as ops_staff → attempts PUT as regular user → asserts 403. Validates that the edit endpoint enforces role authorization at the API boundary.

**Evidence:** `repo/tests/api/ActivityTest.php:496-513`
Status: **VERIFIED**

---

## New Tests — Frontend Unit (`test-render.js`)

Extended `tests/frontend/test-render.js` with 6 `assert.strictEqual` cases for `fmtDatetimeLocal()`:
- Space-separated string → T-separator with 16-char truncation
- Already-T-separated ISO 8601 string → truncated to HH:MM precision
- null → empty string
- empty string → empty string
- undefined → empty string
- Midnight datetime preserved correctly

**Evidence:** `repo/tests/frontend/test-render.js:108-136`
Status: **VERIFIED**

---

## Documentation

README.md Activity List UI section updated to document:
- Edit button visible to admin/ops_staff on every activity row
- Edit modal pre-populated with all fields including eligibility tags and supplies
- How tag and supply inputs use shared normalization helpers
- Post-publish versioning: editing a published activity creates a version; editing a draft does not
- Role restriction: regular users, reviewers, and team leads get 403 on edit endpoint

**Evidence:** `repo/README.md` — Activity List UI section, "Activity edit flow" paragraph
Status: **VERIFIED**

---

## Summary Table

| Item | Type | Resolution | Status |
|------|------|------------|--------|
| Tags input missing from create UI | Medium (round-12) | Already fixed in audit_report-1-fix_check.md | VERIFIED (prior) |
| Edit modal with all fields | Medium (round-12 completion) | Added full Layui edit modal + form submit handler | VERIFIED |
| Edit button in activity rows | Medium (round-12 completion) | `layui-btn-warm` Edit button for canEdit roles | VERIFIED |
| `fmtDatetimeLocal` helper | New helper for edit pre-population | Extracted + tested | VERIFIED |
| `testUpdatePublishedActivity_withTagsAndSupplies_versionContainsDiff` | API test | Added; asserts version created with diff | VERIFIED |
| `testUpdateDraftActivity_noVersionCreated` | API test | Added; asserts no version for draft edit | VERIFIED |
| `testEditActivity_opsStaff_returns200` | E2E test | Added; full HTTP flow for authorized role | VERIFIED |
| `testEditActivity_regularUser_returns403` | E2E test | Added; confirms role boundary enforced | VERIFIED |
| `fmtDatetimeLocal` unit tests | Frontend unit | 6 cases in test-render.js | VERIFIED |
| README edit flow documentation | Docs | Activity edit flow section added | VERIFIED |
