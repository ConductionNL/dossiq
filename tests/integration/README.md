# Procest API-contract tests (Newman)

Newman/Postman contract tests that exercise procest's HTTP controllers directly,
locking the API contract. Per the gate-19 split, **API/contract correctness lives
in Newman**; Playwright drives the UI only.

## What is covered

| Folder | Endpoints | Happy | Error | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | OR workflow-template lookup, case seeding | seeds caseType + transition IDs + two cases | — | — |
| 1. Status Transition Engine | `GET /api/case/{id}/available-transitions`, `GET .../transition-history`, `POST .../transition` | returns real transitions/current/history + executes a valid transition | 409 guard-failed, 404 unknown transition, 400 missing `transitionId` | 401 no-auth |
| 2. Case (zaak) CRUD | OpenRegister `/api/objects/{register}/{schema}` (ADR-022; procest owns no case-CRUD controller) | create → read → update → delete with id capture | — | anon read (OR reads are not auth-gated — see note) |
| 3. Settings | `GET /api/settings`, `POST /api/settings/load` | 200 + contract shape | — | 401 no-auth, 401 invalid-auth |
| 4. Complaints | `GET /api/complaints`, `POST /api/complaints` | — (see KNOWN BUG) | 400 missing-fields validation | 401 no-auth |
| 9. Teardown | deletes the seeded cases | idempotent cleanup | — | — |

The collection is **self-contained and idempotent**: setup requests seed the
prerequisite OpenRegister objects (a fresh case at the workflow start status, and
a "guard" case parked at a guarded transition with the required field missing),
and teardown deletes everything created.

### Phase-0 fixes locked

- The transition engine returns **real data** (`available-transitions` lists the
  workflow start transition; `transition-history` replays the statusRecord chain).
- The controller body-parsing fix (read `getParams()`, not the protected
  `getContent()`) — the valid-transition POST returns 200, not 500.
- An **unavailable guarded transition is rejected with 409** (`GuardFailedException`),
  not 500, and the error message is static (no leaked exception text).

## Known bug (quarantined, NOT a fake pass)

Every `/api/complaints*` endpoint currently returns **HTTP 500**: `ComplaintService`
and `ComplaintController::categories` call `OCA\OpenRegister\Service\ObjectService::findObjects()`,
which **does not exist** — OpenRegister's real API is `findAll(array $config)`. The
same wrong call appears in ~20 sites across `lib/` (ComplaintService, HearingService,
several BackgroundJob/Cron/Repair classes, DsoController, RaadsinformatieFeedController).

The collection documents this honestly: the complaints **no-auth** (401) and
**create-validation** (400) cases are real contract assertions, while
`complaints index QUARANTINED` asserts the *current* 500 so the suite stays green
**without faking a pass**. When the app is fixed (`findObjects` → `findAll`), that
quarantine test goes RED — flip it to a happy-path 200 assertion at that point.

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
npx newman run procest.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var adminUser=admin \
  --env-var adminPass=admin
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to `npx newman`,
and serialises runs under `flock /tmp/uiaudit-procest.lock` to avoid tripping the
Nextcloud brute-force protection when multiple agents run in parallel.

## Auth-isolation detail (important for reuse)

Newman keeps a per-run cookie jar. Authenticated requests against `baseUrl`
(`localhost`) establish a Nextcloud session cookie; because the jar is shared,
that cookie would silently authenticate the no-auth / invalid-auth requests too
(they then return 200 instead of 401). Two measures keep the authorization tests
honest:

1. **Host split** — authenticated requests use `{{baseUrl}}` (`http://localhost:8080`);
   the no-auth / invalid-auth requests use `{{noAuthBase}}` (`http://127.0.0.1:8080`).
   NC session cookies are host-scoped, so the `localhost` session is never sent to
   `127.0.0.1`, making those requests genuinely unauthenticated. `run-newman.sh`
   derives `noAuthBase` from `BASE_URL` automatically (override with `NO_AUTH_BASE`).
2. **`--ignore-redirects` + `Accept: application/json`** — unauthenticated requests
   get NC's JSON `401`, not the `303`→login-page `200` HTML that a browser `Accept`
   would follow.

This is the reusable Newman authz pattern for the fleet.

### OpenRegister object reads are not auth-gated

Case CRUD is delegated to OpenRegister (ADR-022). OR's object-read API returns the
object to an **anonymous** request (`200`) — authorization on case data is OR's
responsibility, not procest's. The folder-2 anon test documents this honestly
rather than asserting a `401` the OR API never returns. The procest controllers
themselves (folders 1, 3, 4) **are** auth-gated and return `401`.

## Collection variables

`baseUrl`, `adminUser`, `adminPass`, plus the deployed OpenRegister IDs
`register=17`, `caseSchema=92`, `complaintSchema=64`. The `caseType`,
`startStatusId`, `midStatusId`, `startTransitionId`, and `guardTransitionId`
variables are **discovered at runtime** from the active workflow template, so the
suite is not pinned to specific seed UUIDs.
