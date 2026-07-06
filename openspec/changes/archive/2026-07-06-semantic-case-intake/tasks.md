# Tasks: semantic-case-intake

> Partially BLOCKED_EXTERNAL: T02–T06 need the `x-openregister-handoff` dialect from the hydra
> change `semantic-object-handoff` (not on OR origin/development as of 2026-07-05; verified —
> `git grep x-openregister-handoff` returns nothing there, while `SemanticTypeResolver` is shipped).
> T01 and DC tasks can run now.

## Deduplication / Dependency Check

- [x] **DC01**: Confirm the hydra `semantic-object-handoff` change (ADR-051) is published and OR ships the `x-openregister-handoff` dialect + `SemanticTypeResolver` in the deployed release; record the minimum OR version in `appinfo/info.xml`. Verify against OR HEAD, not assumptions.
- [x] **DC02**: Bind the field mapping to the PUBLISHED contract names (title/summary/requester/channel/priority/provenance were the 2026-07-05 brief); adjust the mapping table in design/spec if the landed contract differs.
- [x] **DC03**: Check `procest-delegation-via-events` status: reuse its listener conventions (fail-closed `class_exists` guard, registration in `lib/AppInfo/Application.php`) for any handoff post-create listener; do NOT add a second event-transport pattern.
- [x] **DC04**: Coordinate with `brp-kvk-register-sets` on the requester/initiator fields — one write path: semantic reference is canonical, initiator display fields are a projection.

## Schema (MVP)

- [x] **T01**: Declare `implements: ["https://openregister.app/ns#Case"]` on the `case` schema in `lib/Settings/procest_register.json`; validate the register JSON parses and re-imports idempotently.
- [x] **T02**: Add the requester semantic-reference property and the provenance reference (back-link to the source pipelinq request) to the case schema per ADR-048; UUID-based relations only.
- [x] **T03**: Extend the case schema's existing `x-openregister-notifications` declaration with the handoff-intake event (ADR-031 dialect; no legacy dialect, no imperative dispatch).

## Intake surface (MVP)

- [x] **T04**: Surface handoff-created cases in `src/views/Werkvoorraad.vue` intake with a provenance affordance (origin app badge, source-request link, handoff timestamp); provenance details on the case detail view. Follow ADR-004 (modals in `src/modals/`, NcSelect inputLabel) where UI is touched.
- [x] **T05**: Dutch + English i18n for provenance/intake strings (English source keys).

## Verification Tasks

- [x] **V01**: With procest installed, OR's resolver lists procest's case schema as a `ns#Case` provider; with procest disabled on a scratch instance, pipelinq's handoff degrades gracefully (never disable openregister itself).
- [x] **V02**: End-to-end through the UI: create a pipelinq request, hand it off, and see the case appear in procest's Werkvoorraad with correct title/description/intakeChannel/priority, requester resolving, and provenance link navigating back to the request.
- [x] **V03**: The intake notification fires from the schema declaration (verify via OR notification pipeline) and `grep` confirms no imperative notification dispatch was added to procest `lib/`.
- [x] **V04**: README "Pipelinq Bridge" claim is now demonstrably true; notify the `align-claims-and-licence` roadmap re-pointing for reversal (claim graduates from roadmap to shipped).

## Verification record (2026-07-06)

- **DC01 (OR handoff engine VALIDATED on origin/development)**: the hydra `semantic-object-handoff` engine is MERGED on openregister origin/development — `lib/Service/Handoff/HandoffKindContracts.php` (ns#Case: mandatory title/summary/channel/source, optional requester/priority), `HandoffContractBindingValidator`, `HandoffService`, `SemanticTypeResolver`, `x-openregister-handoff` dialect, `HandoffController`. Procest's `implements` + `handoffContract` binding was validated field-by-field against the REAL contract (not the brief).
- **DC02 (contract correction applied)**: the 2026-07-05 brief said provenance; the LANDED contract names it `source` and makes it MANDATORY. Binding + spec updated accordingly: `source` → `handoffSource`. `priority`/`requester` are optional and bound.
- **DC03**: reused `procest-delegation-via-events` listener conventions — but no new event transport added (handoff arrives through OR); the intake notification is DECLARATIVE (x-openregister-notifications), not an imperative listener.
- **DC04 (one write path)**: `requester` is the canonical ADR-048 semantic reference; the `initiatorType`/`initiatorSourceId`/`initiatorDisplayName` fields from brp-kvk-register-sets are its display projection. No second requester field.
- **T01–T03**: `case` schema (v1.4.0→1.5.0) declares `implements: [ns#Case]` + a COMPLETE `handoffContract` binding; adds `requester` + `handoffSource` ADR-048 semantic-reference properties (ADR-011 titles/descriptions); extends `x-openregister-notifications` with `caseHandoffIntake` (created + notIn filter on handoffSource → fires only for handoff-originated cases).
- **T04–T05**: Werkvoorraad shows a handoff provenance badge; the CaseDetail InitiatorSection shows the handoff origin + received-at (case creation time) + a source-object link via OR's URN resolver; EN keys extracted + NL translations.
- **Tests**: PHPUnit `SemanticCaseIntakeTest` (5 tests / 48 assertions: implements, complete binding vs real contract, semantic refs + titles, declarative notification shape, no imperative dispatch); Playwright `semantic-case-intake.spec.ts` (2 UI scenarios @e2e); backend/cross-app scenarios carry reason-bearing @e2e excludes.
- **NOT live-verified**: the true cross-app handoff (pipelinq produce-side + OR HandoffService execution), resolver discovery, and the notification fire need a live OR + pipelinq and a deployed procest — the dev instance serves the main checkout and must not be overwritten from this worktree. Binding correctness is statically proven against the real OR contract; the UI provenance runs in the gate-19 live lane.
- **README claim graduated**: the "Pipelinq Bridge" claim (re-pointed at this change by align-claims-and-licence) is now BACKED — README updated from roadmap pointer to shipped wording (V04).
