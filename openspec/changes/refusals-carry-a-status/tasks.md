# Tasks: refusals-carry-a-status

Tier: V1. Kind: code. Row Q10.14. Ordered so the instrument exists before
the triage.

- [x] 1.1 `tests/Unit/Architecture/ServiceCatchReturnsNullTest.php` and
  `catch-return-null.allowlist.json` seeded with the measured sites,
  each with class and reason (D-1). Fixture test for a new site and for
  the ceiling.
  - `@spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md`
  - Seeded at **203 sites in 121 files**, not the 47 the proposal
    estimated. The proposal measured a three-LINE window; D-1 specifies
    three STATEMENTS, and a multi-line logger call in front of the
    return is the same shape a line window cannot see. The same tree
    reads 90 by lines and 203 by statements, and two independent
    implementations of the statement walk agree on 203. The ceiling is
    the measured count, so it can only go down.
- [x] 1.2 Triage: classify the sites into refusal, degradation,
  read-miss; record the counts here.
  - **4 refusal**, all converted in this change, so none is
    allowlisted: a refusal leaves the list rather than sitting in it
    (REQ-QG-CRN-2).
  - **47 degradation**: the method resolves a collaborator, or its log
    line names a sibling app, an engine or a binding that was absent.
  - **156 read-miss**: a find or a list that answers empty when the read
    fails.
  - The class and reason on every entry were derived from the site's own
    evidence (what it returns, whether and how it logs). They record
    what the site does, not that it is right. **74 of the 203 log
    nothing at all**, which is the number the next batch should read
    first. The `refusal` class was assigned by hand over the 21
    guard-resolver sites, the class hydra gate 8 exists for; gate 8's
    own detector reports **zero** on this tree, because it matches on
    the resolver's NAME (`*auth*`, `*permission*`, `*role*`, `*guard*`)
    and dossiq's fail-open resolvers are called `getObjectService()` and
    `loadActiveMatrix()`.
- [x] 2.1 Convert the `refusal` sites in batches of no more than ten per
  PR: typed exception, controller translation (ADR-105), entry removed;
  one mutation per new status assertion recorded in the PR body.
  - Batch 1, four sites, each a guard whose empty answer became a
    sentence about the user:
    `MandaatCheckService::getApplicableMandaten`,
    `Beschikking\MandaatVerifier::resolveMandaatRegeling`,
    `RoleResolverService::loadCaseRoles`,
    `TenantAuthenticationService::loadActiveMatrix`.
  - Translated in `MandaatMatrixController`, `BeschikkingController`,
    `RoutingController` and `MandateValidationMiddleware::afterException`.
  - The transition engine's four refusal codes
    (`transition_from_status_mismatch`, `transition_unauthorized`,
    `transition_conflict`, `result_type_required`) now carry the same
    shape, so the endpoint the spec's scenario measures answers a rule
    rather than "Could not execute transition".
- [x] 2.2 `lib/Exception/RefusedException.php` (rule slug, message) and
  its 409 mapping, where no tracked class fits.
  - Carries rule (kebab-case, for `error`), sentence (static, authored
    at the throw site), and status: 409 refused, 403 forbidden, 422
    unprocessable, 503 indeterminate. `getMessage()` stays the
    snake_case code, because `CaseActionProvider::REFUSAL_CODES` and the
    frontend's `refusalMessage()` both read it.
- [x] 3.1 The controller suites without `getStatus()`: add the assertion
  on both branches; prove each with a mutation.
  - Four of the thirteen exercise a controller response and gained the
    assertion: `SettingsControllerWriteTest`, `DSOIntakeControllerTest`,
    `AiSettingsControllerShapeTest`, `DrcFileIdStampContractTest`.
  - The other nine do not call a controller method at all: they cover a
    guard service, a SOAP dispatcher, rate-limit attributes, a config
    flag, an OpenAPI document and a query limit. Adding `getStatus()`
    there would assert a status nothing produced. They are listed in the
    PR body so the count is not silently dropped.
- [x] 4.1 `tests/e2e/refusal-status.spec.ts`; `openspec validate
  refusals-carry-a-status --strict`.
