# Test Coverage Audit

## Project Type Detection
- README top does not declare one of `backend|fullstack|web|android|ios|desktop` as a type token.
- Inferred type: **fullstack** (ThinkPHP backend routes + static frontend).
- Evidence: `repo/README.md:1`, `repo/backend/route/api.php:1-112`, `repo/frontend/index.html:1`

## Backend Endpoint Inventory
Resolved from `repo/backend/route/api.php` including `Route::group('api', ...)` prefix.

1. POST `/api/auth/login`
2. POST `/api/auth/logout`
3. GET `/api/users`
4. POST `/api/users`
5. GET `/api/users/:id`
6. PUT `/api/users/:id`
7. DELETE `/api/users/:id`
8. GET `/api/activities`
9. POST `/api/activities`
10. GET `/api/activities/:id`
11. PUT `/api/activities/:id`
12. PATCH `/api/activities/:id/state`
13. GET `/api/activities/:id/versions`
14. POST `/api/activities/:id/signups`
15. DELETE `/api/activities/:id/signups/:uid`
16. POST `/api/activities/:id/saves`
17. DELETE `/api/activities/:id/saves`
18. GET `/api/activities/:id/tasks`
19. POST `/api/activities/:id/tasks`
20. PUT `/api/activities/:id/tasks/:tid`
21. DELETE `/api/activities/:id/tasks/:tid`
22. GET `/api/orders`
23. POST `/api/orders`
24. GET `/api/orders/:id`
25. PATCH `/api/orders/:id/state`
26. POST `/api/orders/:id/refund`
27. POST `/api/orders/:id/invoice-corrections`
28. PATCH `/api/invoice-corrections/:id/review`
29. GET `/api/shipments`
30. POST `/api/shipments`
31. GET `/api/shipments/:id`
32. POST `/api/shipments/:id/events`
33. PATCH `/api/shipments/:id/deliver`
34. POST `/api/shipments/:id/exceptions`
35. GET `/api/subscriptions`
36. PUT `/api/subscriptions`
37. GET `/api/violation-rules`
38. POST `/api/violation-rules`
39. PUT `/api/violation-rules/:id`
40. DELETE `/api/violation-rules/:id`
41. GET `/api/violations`
42. POST `/api/violations`
43. GET `/api/violations/:id`
44. POST `/api/violations/:id/evidence`
45. POST `/api/violations/:id/appeals`
46. PATCH `/api/violations/:id/appeals/review`
47. GET `/api/point-summary/users/:uid`
48. GET `/api/point-summary/groups/:gid`
49. GET `/api/search`
50. GET `/api/search/logistics`
51. GET `/api/recommendations`
52. GET `/api/recommendations/activities/:id`
53. GET `/api/recommendations/orders/:id`
54. GET `/api/dashboards`
55. POST `/api/dashboards`
56. GET `/api/dashboards/:id`
57. PUT `/api/dashboards/:id`
58. DELETE `/api/dashboards/:id`
59. POST `/api/dashboards/:id/favorite`
60. DELETE `/api/dashboards/:id/favorite`
61. POST `/api/dashboards/:id/export`
62. GET `/api/widgets/data`
63. GET `/api/users/:id/sensitive`

Evidence: `repo/backend/route/api.php:7-110`

## API Test Mapping Table
Legend: `TNH` = true no-mock HTTP.

| Endpoint | Covered | Type | Test files | Evidence |
|---|---|---|---|---|
| `POST /api/auth/login` | yes | TNH | `AuthTest.php` | `repo/tests/api/AuthTest.php:15-31` |
| `POST /api/auth/logout` | yes | TNH | `AuthTest.php` | `repo/tests/api/AuthTest.php:82-99` |
| `GET /api/users` | yes | TNH | `UserTest.php` | `repo/tests/api/UserTest.php:14-44` |
| `POST /api/users` | yes | TNH | `UserTest.php` | `repo/tests/api/UserTest.php:82-126` |
| `GET /api/users/:id` | yes | TNH | `UserTest.php` | `repo/tests/api/UserTest.php:46-80` |
| `PUT /api/users/:id` | **no** | — | — | No `PUT /api/users/{id}` call found in `repo/tests/api/UserTest.php` |
| `DELETE /api/users/:id` | yes | TNH | `UserTest.php` | `repo/tests/api/UserTest.php:128-141` |
| `GET /api/activities` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:14-31` |
| `POST /api/activities` | yes | TNH | `ActivityTest.php`,`SearchTest.php` | `repo/tests/api/ActivityTest.php:33-69` |
| `GET /api/activities/:id` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:71-91` |
| `PUT /api/activities/:id` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:169-236` |
| `PATCH /api/activities/:id/state` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:93-131` |
| `GET /api/activities/:id/versions` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:133-167` |
| `POST /api/activities/:id/signups` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:238-302` |
| `DELETE /api/activities/:id/signups/:uid` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:550-590` |
| `POST /api/activities/:id/saves` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:304-347` |
| `DELETE /api/activities/:id/saves` | yes | TNH | `ActivityTest.php` | `repo/tests/api/ActivityTest.php:349-388` |
| `GET /api/activities/:id/tasks` | yes | TNH | `TaskTest.php` | `repo/tests/api/TaskTest.php:81-84` |
| `POST /api/activities/:id/tasks` | yes | TNH | `TaskTest.php` | `repo/tests/api/TaskTest.php:18-48` |
| `PUT /api/activities/:id/tasks/:tid` | yes | TNH | `TaskTest.php` | `repo/tests/api/TaskTest.php:50-63` |
| `DELETE /api/activities/:id/tasks/:tid` | yes | TNH | `TaskTest.php` | `repo/tests/api/TaskTest.php:66-78` |
| `GET /api/orders` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:12-37` |
| `POST /api/orders` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:39-89` |
| `GET /api/orders/:id` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:91-120` |
| `PATCH /api/orders/:id/state` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:122-171` |
| `POST /api/orders/:id/refund` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:173-225` |
| `POST /api/orders/:id/invoice-corrections` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:227-273` |
| `PATCH /api/invoice-corrections/:id/review` | yes | TNH | `OrderTest.php` | `repo/tests/api/OrderTest.php:275-335` |
| `GET /api/shipments` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:129-145` |
| `POST /api/shipments` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:31-37` |
| `GET /api/shipments/:id` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:147-167` |
| `POST /api/shipments/:id/events` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:38-46` |
| `PATCH /api/shipments/:id/deliver` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:48-57` |
| `POST /api/shipments/:id/exceptions` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:109-117` |
| `GET /api/subscriptions` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:59-67` |
| `PUT /api/subscriptions` | yes | TNH | `FulfillmentTest.php` | `repo/tests/api/FulfillmentTest.php:69-81` |
| `GET /api/violation-rules` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:250-267` |
| `POST /api/violation-rules` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:23-39` |
| `PUT /api/violation-rules/:id` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:270-291` |
| `DELETE /api/violation-rules/:id` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:295-317` |
| `GET /api/violations` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:57-68` |
| `POST /api/violations` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:41-55` |
| `GET /api/violations/:id` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:320-336` |
| `POST /api/violations/:id/evidence` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:202-239` |
| `POST /api/violations/:id/appeals` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:70-88` |
| `PATCH /api/violations/:id/appeals/review` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:90-121` |
| `GET /api/point-summary/users/:uid` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:123-133` |
| `GET /api/point-summary/groups/:gid` | yes | TNH | `ViolationTest.php` | `repo/tests/api/ViolationTest.php:164-169` |
| `GET /api/search` | yes | TNH | `SearchTest.php` | `repo/tests/api/SearchTest.php:26-75` |
| `GET /api/search/logistics` | yes | TNH | `SearchTest.php` | `repo/tests/api/SearchTest.php:76-598` |
| `GET /api/recommendations` | yes | TNH | `RecommendationTest.php` | `repo/tests/api/RecommendationTest.php:23-290` |
| `GET /api/recommendations/activities/:id` | yes | TNH | `RecommendationTest.php` | `repo/tests/api/RecommendationTest.php:55-68` |
| `GET /api/recommendations/orders/:id` | yes | TNH | `RecommendationTest.php` | `repo/tests/api/RecommendationTest.php:291-367` |
| `GET /api/dashboards` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:55-70` |
| `POST /api/dashboards` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:25-54` |
| `GET /api/dashboards/:id` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:88-108` |
| `PUT /api/dashboards/:id` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:71-87` |
| `DELETE /api/dashboards/:id` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:88-100` |
| `POST /api/dashboards/:id/favorite` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:111-126` |
| `DELETE /api/dashboards/:id/favorite` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:127-148` |
| `POST /api/dashboards/:id/export` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:173-207` |
| `GET /api/widgets/data` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:150-172` |
| `GET /api/users/:id/sensitive` | yes | TNH | `DashboardTest.php` | `repo/tests/api/DashboardTest.php:209-229` |

## API Test Classification
1. **True No-Mock HTTP**: Present (all API tests use real HTTP requests via `GuzzleHttp\Client` to `BASE_URL`).
   - Evidence: `repo/tests/TestCase.php:13-21`, `repo/tests/TestCase.php:33-41`
2. **HTTP with mocking**: Not found.
3. **Non-HTTP tests**: Present (frontend JS unit scripts executed with Node assert).
   - Evidence: `repo/tests/frontend/test-fmt.js:1-58`, `repo/tests/frontend/test-tags.js:1-74`, `repo/tests/frontend/test-render.js:1-136`

## Mock Detection
- No `jest.mock`, `vi.mock`, `sinon.stub`, `Mockery`, or transport/controller/service stubbing found in test suite.
- Evidence: repository grep over `repo/tests` returned no matches.

## Coverage Summary
- Total endpoints: **63**
- Endpoints with HTTP tests: **62**
- Endpoints with true no-mock HTTP tests: **62**
- HTTP coverage: **98.41%**
- True API coverage: **98.41%**
- Uncovered endpoint: `PUT /api/users/:id`
  - Evidence: route exists `repo/backend/route/api.php:19`; no corresponding PUT invocation in `repo/tests/api/UserTest.php`

## Unit Test Summary
### Backend Unit Tests
- Direct unit tests for controllers/services/repositories/middleware: **not present** (backend tests are API HTTP tests).
- API HTTP coverage is strong for core controller paths.
- Important backend module not directly unit tested: `User/update` path.

### Frontend Unit Tests (Strict Requirement)
- Frontend test files exist: `repo/tests/frontend/test-fmt.js`, `repo/tests/frontend/test-tags.js`, `repo/tests/frontend/test-render.js`
- Framework/tool: Node built-in `assert` (no Jest/Vitest/RTL).
- Covered logic (mirrored inline): `fmt`, `normalizeActivityTags`, `normalizeActivitySupplies`, `parseTagInput`, `fmtDatetimeLocal`.
- Critical strict gap: tests **do not import/render actual frontend modules/components**; functions are redefined inline.
  - Evidence: `repo/tests/frontend/test-fmt.js:9-22`, `repo/tests/frontend/test-tags.js:9-16`, `repo/tests/frontend/test-render.js:12-26`
- **Frontend unit tests: MISSING** (under this prompt’s strict detection rule).

### Cross-Layer Observation
- Backend API testing is extensive, but frontend tests are implementation-mirror scripts rather than module-import tests; this leaves FE integration regressions undetected.

## API Observability Check
- Strong: tests usually specify endpoint, request payload/query, and status/body assertions.
  - Evidence examples: `repo/tests/api/OrderTest.php:227-335`, `repo/tests/api/SearchTest.php:239-271`, `repo/tests/api/FulfillmentTest.php:147-159`

## Tests Check
- Success/failure/validation/authz paths are broadly present in API tests.
- `run_tests.sh` is Docker-based; does not require local package installation.
  - Evidence: `repo/run_tests.sh:21-53`
- Fullstack FE↔BE E2E scenarios are not explicitly present as browser-flow tests; partial compensation exists via high API coverage.

## Test Coverage Score (0–100)
- **84/100**

## Score Rationale
- + Very high HTTP endpoint coverage and true no-mock API testing.
- - One uncovered backend endpoint (`PUT /api/users/:id`).
- - Strict frontend unit-test rule not met (tests mirror code instead of importing real FE modules).
- - No explicit FE↔BE E2E flow tests.

## Key Gaps
1. Missing API coverage for `PUT /api/users/:id`.
2. Frontend tests are not bound to real frontend modules/components under strict rule.

## Confidence & Assumptions
- Confidence: medium-high.
- Static-only limitation: no runtime execution performed.
- Coverage conclusion is based on route + test source inspection only.

---

# README Audit

## README Location
- Found: `repo/README.md`

## Hard Gate Evaluation
- Formatting: **PASS** (`repo/README.md:1-260`)
- Startup instructions (backend/fullstack): **PASS** (`docker compose up ...` and literal `docker-compose up ...` present)
  - Evidence: `repo/README.md:22`, `repo/README.md:28`
- Access method (URL + port): **PASS**
  - Evidence: `repo/README.md:31`
- Verification method: **PASS** (curl + browser flow with expected outcomes)
  - Evidence: `repo/README.md:48-77`
- Environment rules (no runtime installs/manual DB setup): **PASS**
  - Evidence: `repo/README.md:13-15`, `repo/README.md:79-93`
- Demo credentials with roles (auth exists): **PASS**
  - Evidence: `repo/README.md:39-46`

## Engineering Quality Review
- Tech stack clarity: good (`repo/README.md:5`)
- Architecture explanation: good (`repo/README.md:97-127`)
- Testing instructions: good (`repo/README.md:79-93`)
- Security/roles overview: present (`repo/README.md:249-257`)
- Workflow and API semantics: detailed (`repo/README.md:131-246`)

## High Priority Issues
- None.

## Medium Priority Issues
- README does not declare project type token at top (`backend|fullstack|web|android|ios|desktop`) as required by this audit prompt’s detection rule.
  - Evidence: `repo/README.md:1-6`
- Architecture section claims “All 60 API routes” but route file currently has 63 endpoint declarations.
  - Evidence: `repo/README.md:111`, `repo/backend/route/api.php:7-110`

## Low Priority Issues
- Verification snippets use `python3 -m json.tool`; this is only for pretty-printing and not required for correctness. Consider optional note/fallback for environments without Python.
  - Evidence: `repo/README.md:56`, `repo/README.md:64`

## Hard Gate Failures
- None.

## README Verdict
- **PASS** (all hard gates satisfied).
