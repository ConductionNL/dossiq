# Design: woo-publication-via-opencatalogi

## Context

Verified at HEAD in both repos before writing any code:

- **procest** (`origin/development`, this repo): `WOODecisionService::assembleDecision()`
  writes a `decision` object (schema config key `decision_schema`, generic
  across besluitvorming/subsidie/WOO domains — no `additionalProperties: false`,
  no `hardValidation: true`) summarising per-document assessments
  (`openbaar`/`deels_openbaar`/`niet_openbaar` counts + `weigeringsgronden`).
  `WOODocumentAssessmentService` stores individual assessments (schema config
  key `woo_assessment_schema`) keyed by `documentRef`/`caseRef`. Neither
  service, nor `WOORedactionService`, nor `WOOAssessmentController`, calls
  anything outside procest's own OpenRegister objects. The `woo-verzoek.json`
  template's 8 status types (`Ontvangst` … `Afgehandeld`) all have
  `actions: null` / `automaticActions: null` — confirmed by parsing the JSON —
  unlike `besluitvorming-workflow`'s status types, which explicitly configure
  `{type: 'besluitvormingPublish'}` actions consumed by
  `Transitions/ActionHandlerRegistry`. The WOO views under
  `src/views/cases/components/` (`WooIntakeForm.vue`, `DocumentAssessmentPanel.vue`,
  `DocumentAssessmentTable.vue`) and `src/views/dashboard/WooDeadlinePanel.vue`
  are **not imported anywhere** in `src/registry.js` or any other `.vue`/`.js`
  file at HEAD — confirmed by grep across `src/`. This is a pre-existing gap
  (the WOO UI layer was built but never wired into the live case-detail
  surface) that predates and is out of scope for this change; it is called
  out here rather than silently worked around, per the "always fix
  pre-existing issues encountered" rule this repo would otherwise apply — but
  wiring the entire WOO case-detail tab surface is a separate, much larger
  change (it touches the caseType-driven dynamic tab/leaf resolution system).
  This change adds its UI to the existing WOO component file so it is ready
  the moment that wiring lands, and does not invent a new page/route to work
  around the gap.

- **OpenCatalogi** (`origin/development`, sibling repo, read via
  `git show origin/development:<path>` — the local working tree may be on a
  different branch): `PublicationsController` (`lib/Controller/PublicationsController.php`)
  is public-read-only — `index`/`show`/`uses`/`used`/`attachments`/`download`,
  no `create`/`update`/`destroy`. `PublicationService::getObjectService()`
  resolves OpenRegister's `ObjectService` from the container — i.e.
  OpenCatalogi's own backend does not have a bespoke write path either; it
  goes straight to OpenRegister. OpenCatalogi's frontend
  (`src/store/modules/object.js`) confirms the same: `saveObject()`,
  `depublish()`/`publish()`-style helpers all target
  `/index.php/apps/openregister/api/objects/{registerId}/{schemaId}[/{objectId}]`.
  The bundled register (`lib/Settings/publication_register.json`, register
  slug `publication`) ships `publication` and `document` schemas (among
  others) with **no `additionalProperties: false` and `hardValidation: false`**
  on `publication` — consistent with `SitemapService` reading
  `publication.tooiCategorieUri` / `publication.tooiCategorieNaam`, fields
  that do **not** appear in the schema's declared `properties` at all. These
  are informally-typed fields OpenCatalogi itself writes/reads ad hoc; this
  change follows the same convention rather than inventing a differently-named
  field.
  - `publication.publicatiedatum` / `publication.depublicatiedatum`: "when set
    to a date in the past, this publication is live" / "withdrawn". This is
    the actual publish/withdraw mechanism — there is no separate
    publish/depublish *endpoint* on the generic Objects API (only on
    `registers#publishToGitHub`/`configuration#publishToGitHub`, unrelated).
  - `document.publication`: `{id, slug, title}` — how a `document` object
    references the `publication` it belongs to.
  - `document.content`: "Base64-encoded file content or file reference" —
    matches procest's own `document` schema field of the same name/shape
    (`lib/Settings/procest_register.json`), so the redacted document's
    existing base64 `content` can be forwarded largely as-is.
  - File bytes can also be attached to any OpenRegister object via the
    confirmed, generic `files#create` route:
    `POST /api/objects/{register}/{schema}/{id}/files` with `name` +
    `content` (base64) — this is OpenRegister's own route (`appinfo/routes.php`
    in the `openregister` repo), not something OpenCatalogi- or
    procest-specific.
  - `catalog.hasWooSitemap` (bool) + `catalog.slug`: a catalog flagged for WOO
    publication. `GET /api/catalogi` (OpenCatalogi's own public route) lists
    catalogs; used only as an optional discovery aid (§ Fallback), not a hard
    dependency.

## Decisions

### D1 — Write through OpenRegister's Objects API, not a bespoke OpenCatalogi route

Since OpenCatalogi itself has no write endpoint for publications and both its
backend and frontend write through OpenRegister's generic Objects API, that
*is* "publishing through OpenCatalogi" in this codebase's actual architecture
— OpenCatalogi owns the register/schema/validation *model*; OpenRegister is
the shared write boundary every app (including OpenCatalogi) uses. Procest
follows exactly the same path. This keeps the integration bound to real,
confirmed routes (`objects#create`, `objects#patch`, `files#create` in
`openregister/appinfo/routes.php`) instead of guessing at a nonexistent
`opencatalogi/api/publications` POST route.

### D2 — Thin HTTP client, not in-process PHP service consumption

Procest has no existing precedent for pulling in an OpenCatalogi PHP class
directly (no Composer dependency, no shared interface). It *does* have a
strong, just-merged precedent for a same-instance peer app: `LibresignApiClient`
— `IClientService` + `IURLGenerator::getBaseUrl()` + service-account
basic auth from `IAppConfig`, one thin class, every field name isolated so a
future correction is one file. `OpenCatalogiApiClient` follows that shape
exactly, targeting OpenRegister's routes (which — unlike the LibreSign case —
are *not* an assumption; they are directly confirmed against
`openregister/appinfo/routes.php` at HEAD).

### D3 — Category mapping is a lookup table, not an inline constant

`WooCategoryMapper::forDecision(array $decision): array` returns
`{code, uri, label}`. Today it has exactly one entry
(`infocat014` → `Woo-verzoeken en -besluiten` →
`https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532`, matching
OpenCatalogi's own `TooiVocabularyService::INFORMATIECATEGORIEEN['infocat014']`
verbatim) keyed by `decision['decisionType']`, with every other decision type
falling back to the same entry (a WOO besluit is definitionally in that
category; there is nothing else it could be). The table shape exists so a
follow-up change can add `infocat016` "Beschikkingen" for
`subsidie`/`BeschikkingService` decisions without touching
`WooPublicationService`.

### D4 — Redacted-only enforcement happens in payload building, asserted by a dedicated method

`WooPublicationService::selectDisclosableDocuments(array $assessments, callable $documentLoader): array`:

- `classification === 'niet_openbaar'` → **excluded**, unconditionally.
- `classification === 'openbaar'` → included, document loaded as-is.
- `classification === 'deels_openbaar'` → included **only if** the assessment
  carries a `redactedDocumentRef` (the id of the anonymized replacement
  document). `WOORedactionService::queueForRedaction()` does not persist
  anything today (its own docblock: "Hook point... deferred to DocuDeskService
  when the docudesk app ships its service interface" for the Docudesk path,
  and the manual path only returns transient instructions) — no finalized-
  redaction field exists anywhere in this codebase yet. `redactedDocumentRef`
  is the minimal read-side contract this change introduces on the assessment
  record (an OpenRegister object, no schema migration needed — see the
  ad hoc-field precedent in the Context section) so that a future redaction-
  finalization step (out of scope here) has somewhere to write the anonymized
  document's id. Until that step exists, every `deels_openbaar` document is
  correctly excluded (never silently published unredacted) — this is the
  fail-safe default, not a placeholder that happens to pass tests.
- There is no code path in this method that can return a document's original
  `content`/`fileId` for a `deels_openbaar` item — the redacted reference is
  the *only* field read for that branch. This is what the unit tests assert
  directly (construct an assessment set with a `deels_openbaar` doc that has
  both an original and redacted reference, assert the built payload contains
  only the redacted one; construct a `niet_openbaar` doc and assert it never
  appears).

### D5 — Absent app / absent config: graceful, not exceptional-for-the-case

`WooPublicationService::checkAvailability(): array` returns
`{available: bool, reason?: string}`. Reasons: `opencatalogi_not_installed`
(`IAppManager::isEnabledForUser('opencatalogi') === false`),
`openregister_unavailable` (mirrors the existing `SettingsService::getObjectService()`
null-check pattern used everywhere else in this app),
`no_publishable_documents` (all documents `niet_openbaar` or
`deels_openbaar`-pending-redaction). The controller surfaces this as a 200
with `{available: false, reason}` (not a 4xx/5xx) so the frontend can render
an admin hint ("OpenCatalogi is not installed on this instance") rather than
a generic error — consistent with `WOORedactionService::isDocuDeskInstalled()`
feature-detection already in this file.

### D6 — Publish/withdraw status stored on the `decision` object, one write

`decision.wooPublication = {publicationId, publicationUrl, status, category,
publishedAt, withdrawnAt?}`. Written via a single `saveObject()` call inside
`WooPublicationService::publish()`/`withdraw()` — the decision object is read
once (to get current fields), the `wooPublication` key is set/merged, and
`saveObject()` is called once. No intermediate partial writes. This mirrors
`WOODecisionService::assembleDecision()`'s own single-`saveObject()` shape and
the ad hoc-field precedent already established by
`PublicationService::publish()` (`$case['publications'][] = …` then one save)
in the besluitvorming domain.

### D7 — Trigger: explicit controller endpoint, not a transition handler

Confirmed (§ Context) the WOO template wires no `actions`/`automaticActions`
on any status type — the WOO flow is 100% explicit-REST-call driven at HEAD,
unlike besluitvorming. Adding a transition-handler here would be the first
and only automatic action on a case type that has never used the engine,
requiring template changes out of proportion to this task. `publishDecision()`
/ `withdrawPublication()` are added to `WOOAssessmentController` next to
`createDecision()`, same shape, same `requireCaseMutationAccess()` guard.

## Fallback: catalog discovery is best-effort, not required

`OpenCatalogiApiClient::resolveCatalog(): ?array` optionally calls the public
`GET /api/catalogi` (via `IClientService`, unauthenticated — it is explicitly
documented as a public endpoint in OpenCatalogi's own `routes.php`), filters
for `hasWooSitemap === true`, and if exactly one match exists, logs its slug
for observability. It does **not** gate publication on this call succeeding
or returning a match — `woo_publication_register`/`woo_publication_document_schema`
(defaulted to OpenCatalogi's own shipped `publication`/`document` slugs) are
what actually address the write. This keeps a clean-install OpenCatalogi with
no catalog configured yet from hard-blocking the WOO publish action (the
"opencatalogi-clean-install-wizard-blocker" gotcha) — the write still lands in
the default register; an admin without a catalog configured simply cannot
browse it publicly yet, which is an OpenCatalogi-side concern, not this
feature's.

## Testing

PHPUnit, HTTP mocked at the `OpenCatalogiApiClient` boundary
(`IClientService`/`IClient` doubles, same technique the LibreSign change
used):

- `WooCategoryMapperTest` — the mapping matrix (D3).
- `WooPublicationServiceTest` — `selectDisclosableDocuments()` redacted-only
  matrix (D4: openbaar included, deels_openbaar+redacted included,
  deels_openbaar+no-redaction excluded, niet_openbaar excluded, mixed set);
  `checkAvailability()` for app-absent / OR-absent / no-publishable-documents
  (D5); `publish()`/`withdraw()` single-save behaviour (D6) with a fake
  `ObjectService`.
- `OpenCatalogiApiClientTest` — request shape assertions (payload, headers,
  URL) against a mocked `IClientService`, matching `LibresignApiClient`'s test
  style.
- `WOOAssessmentControllerTest` — new endpoints' auth guard + happy/error
  paths (extends the existing test file).
