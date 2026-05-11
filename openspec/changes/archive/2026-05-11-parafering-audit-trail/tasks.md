# Tasks: parafering-audit-trail

## Deduplication Check

- [ ] **D01**: Before writing any new code, search `lib/Service/`, `lib/Listener/`, and `lib/Validator/` for any existing parafering audit handling. Verify no prior `ParaferingAuditListener` exists (expected: only the retired `parafering-audit-trail` capability marker exists in `openspec/specs/`). Confirm OR's `audit-trail-immutable` capability is available in the registered OR version (check `openregister/openspec/specs/audit-trail-immutable/spec.md`). Verify `ParafeerRouteService` and `ParafeerActieService` are reachable from this branch and have completion hooks (the `f5a9450` / `c007575` commits already exposed them). Check `lib/Util/IpRedactor.php` for an existing IP redaction helper — reuse if present, otherwise add a 3-line inline helper. Document all findings in this section before proceeding to T01.

---

## Schema

- [ ] **T01**: Add `paraferingAuditEntry` schema to `lib/Settings/procest_register.json` under `components.schemas`. Properties exactly as in `design.md` Data Model section: `voorstel`, `step`, `transitionType` (enum of 6 values), `actor`, `actorRole` (enum of 6 values), `timestamp` (ISO 8601), `reason`, `contentSnapshot` (object), `ipAddress`, `auditEntryHash`. Required: `voorstel`, `transitionType`, `actor`, `actorRole`, `timestamp`, `contentSnapshot`, `ipAddress`, `auditEntryHash`. Schema.org type: `schema:Action`. Add a manifest index page declaration (`type: 'index'`) under `components.x-pages[]` so the entries are browsable from the admin UI without bespoke views. Add 3 realistic Dutch seed entries (one each for `started`, `paraferd`, `terugsturen`). Slugs: `audit-voorstel-0042-started`, `audit-voorstel-0042-step2-paraferd`, `audit-voorstel-0055-step2-terugsturen`.

---

## Backend: Domain Event

- [ ] **T02**: Create `lib/Event/ParafeerTransitionEvent.php` extending `\OCP\EventDispatcher\Event`. Constructor signature: `__construct(string $voorstelId, string $transitionType, ?int $step, string $actor, string $actorRole, ?string $reason)`. Getters for each. File-level docblock with `SPDX-License-Identifier: EUPL-1.2`, `@author Conduction Development Team <info@conduction.nl>`, `@spec openspec/changes/parafering-audit-trail/tasks.md#T02`.

---

## Backend: Audit Listener

- [ ] **T03**: Create `lib/Listener/ParaferingAuditListener.php` implementing `\OCP\EventDispatcher\IEventListener<ParafeerTransitionEvent>`. In `handle(Event $event)`:
  1. Reject the event if not an instance of `ParafeerTransitionEvent`.
  2. Fetch the voorstel via `ObjectService::findObject($register, 'voorstel', $event->getVoorstelId())`.
  3. Build `contentSnapshot` by copying exactly the fields `onderwerp`, `document`, `bijlagen`, `routeSnapshot`, `currentStep`, `status` from the voorstel — never dereference further (no recursive expansion).
  4. Derive IP via `IRequest::getRemoteAddress()` and redact to /24 (IPv4) or /48 (IPv6) via `IpRedactor` (or the 3-line inline helper if D01 found none).
  5. Build the entry array (all 9 schema fields plus `auditEntryHash`).
  6. Compute `auditEntryHash` as `hash('sha256', json_encode($entryWithoutHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))`.
  7. Call `ObjectService::saveObject($register, 'paraferingAuditEntry', $entry)` (3 positional args — ADR-001).
  8. Catch all `\Throwable`, log via `$this->logger->error()` with full exception context, and SWALLOW — the listener MUST NOT propagate exceptions back to the routing service (audit-write failure must not block the operational transition; instead it is detectable via the OR audit-trail-immutable mutation log).

  File carries `@spec openspec/changes/parafering-audit-trail/tasks.md#T03`, `@author Conduction Development Team <info@conduction.nl>`, `@license EUPL-1.2`. Register the listener via `appinfo/info.xml` `<events>` element.

- [ ] **T04**: Dispatch `ParafeerTransitionEvent` from `ParafeerRouteService::startRoute`, `::completeStep`, `::skipStep`, `::addAdHocStep` AFTER the operational save succeeds and BEFORE the method returns. Inject `IEventDispatcher` via constructor — do NOT instantiate listeners directly. Dispatch the same event from `ParafeerActieService::recordAction` after the `parafeeractie` save succeeds. NEVER call the audit listener method directly — always via the event bus, so that a future second listener (e.g. real-time SIEM streaming) attaches without modifying service code.

---

## Backend: Append-Only Validator

- [ ] **T05**: Create `lib/Validator/ParaferingAuditAppendOnlyValidator.php`. Hook into OR's pre-save validation pipeline (verify exact hook name during D01 — likely `OCA\OpenRegister\Event\ObjectBeforeSaveEvent`). Logic:
  - If the target schema is NOT `paraferingAuditEntry`: no-op (return).
  - If the operation is INSERT (no existing `id`): allow.
  - If the operation is UPDATE or DELETE on an existing `paraferingAuditEntry`: throw `\OCP\AppFramework\OCS\OCSForbiddenException` with the static message `'Audit entries are append-only'`. NEVER include exception details in the message.
  - Also validate on INSERT: `transitionType` is one of the 6 enum values; `actorRole` is one of the 6 enum values; `timestamp` matches ISO 8601 with `Z` suffix (UTC); `auditEntryHash` is exactly 64 lowercase hex characters. Reject with static messages on mismatch (`'Invalid transitionType'`, `'Invalid actorRole'`, `'Timestamp must be UTC ISO 8601'`, `'Invalid audit hash'`).

  Register as an event listener via `appinfo/info.xml`. File carries `@spec`, `@author`, `@license` PHPDoc tags.

---

## Backend: Manifest Index Page (T01 already declares it in the JSON; this task wires the UI hook)

- [ ] **T06**: Verify that the manifest index page declared in T01 renders at `/apps/procest/parafering-audit` via the OR manifest router. NO bespoke Vue component — the manifest pattern provides the index UI generically (filter, sort, paginate by `transitionType`, `actor`, `voorstel`, `timestamp` range). If a procest-specific listing column overlay is needed (e.g. a Dutch label for `transitionType` values), add it via a manifest-aware column override JSON block under `components.x-pages[].listing.columns[]` in `procest_register.json` — do NOT write a custom Vue view.

---

## Backend: Archive Export Endpoint

- [ ] **T07**: Create `lib/Controller/ParaferingAuditExportController.php`. Endpoint:
  - `GET /api/parafering-audit-trail/export?voorstel={uuid}` — `#[NoAdminRequired]` paired with a manual group check requiring membership of one of: `auditors`, `secretariaat`, `beheerders` (resolve via `IGroupManager`). On missing membership: return 403 with `{"message": "Audit export requires auditor role"}`.
  - Required query param `voorstel`; return 400 if missing.
  - Fetch the voorstel; if not found return 404.
  - Fetch all `paraferingAuditEntry` records for that voorstel via `ObjectService::findObjects` sorted ascending by `timestamp`.
  - Build the response envelope per `design.md` Archive Export Format section: `metadata` block (schema `MDTO 1.0`, exportedAt, voorstel uuid, voorstelOnderwerp, retentionUntil = timestamp of `completed` entry + 20 years if a `completed` entry exists else 7 years, selectielijstCategory, exportedBy, entryCount) and `entries` array.
  - Return 200 JSON.
  - NEVER include `$e->getMessage()` for unexpected exceptions — catch and return 500 with `{"message": "Export failed"}`.

  Add route to `appinfo/routes.php`:
  ```php
  ['name' => 'parafering_audit_export#index', 'url' => '/api/parafering-audit-trail/export', 'verb' => 'GET'],
  ```
  Place BEFORE any wildcard `{slug}` routes. File carries `@spec openspec/changes/parafering-audit-trail/tasks.md#T07`, `@author`, `@license`.

---

## Translations

- [ ] **T08**: Add the following translation keys to `l10n/en.json` (English source) and `l10n/nl.json` (Dutch translation). All user-visible labels for the manifest index page MUST resolve via `t(appName, '...')` — never hardcoded:

  | English key | Dutch translation |
  |-------------|-------------------|
  | `Parafering audit trail` | `Paraferings-audittrail` |
  | `Transition type` | `Type transitie` |
  | `Actor role` | `Rol bij actie` |
  | `Content snapshot` | `Inhoud op moment van actie` |
  | `Started` | `Gestart` |
  | `Endorsed (paraferd)` | `Geparafeerd` |
  | `Advised` | `Geadviseerd` |
  | `Returned` | `Teruggestuurd` |
  | `Route changed` | `Route gewijzigd` |
  | `Completed` | `Voltooid` |
  | `Audit entries are append-only` | `Audit-vermeldingen zijn alleen toevoegbaar` |
  | `Audit export requires auditor role` | `Audit-export vereist auditorrol` |
  | `Export audit trail` | `Audittrail exporteren` |
  | `Retention until` | `Bewaartermijn tot` |

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Event/ParafeerTransitionEvent.php lib/Listener/ParaferingAuditListener.php lib/Validator/ParaferingAuditAppendOnlyValidator.php lib/Controller/ParaferingAuditExportController.php` → zero results (all files have license header in main docblock per SPDX-in-docblock convention).

- [ ] **V02**: `grep -rn 'getMessage()' lib/Controller/ParaferingAuditExportController.php` → zero results (no raw exception messages in API responses).

- [ ] **V03**: Curl `POST` or `PUT` against the OR generic write endpoint targeting a `paraferingAuditEntry` UPDATE → 403 with `{"message": "Audit entries are append-only"}`. Curl DELETE → same 403. Curl POST a new entry directly (i.e. bypassing the listener) → also rejected because audit entries MUST originate from the listener that computes `auditEntryHash` server-side (validator additionally checks hash format on INSERT).

- [ ] **V04**: End-to-end: submit a voorstel, complete 3 parafering steps, return once, skip a step (route-changed), and finally accord. Verify that 6 `paraferingAuditEntry` records exist for that voorstel, in chronological order, with `transitionType` values `started`, `paraferd`, `paraferd`, `terugsturen`, `route-changed`, `completed`. Verify `contentSnapshot` differs across entries (reflecting voorstel evolution). Verify `auditEntryHash` on every entry matches `sha256(canonical_json(entry_without_hash))`.

- [ ] **V05**: Curl `GET /api/parafering-audit-trail/export?voorstel={uuid}` as a member of `auditors` → 200 with `metadata` (including `retentionUntil` = `completed.timestamp + 20 years`) and `entries` array sorted ascending by `timestamp`. Curl as a non-auditor non-member user → 403 with static message. Curl without `voorstel` param → 400.
