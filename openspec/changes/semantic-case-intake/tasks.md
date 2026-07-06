# Tasks: semantic-case-intake

> Partially BLOCKED_EXTERNAL: T02–T06 need the `x-openregister-handoff` dialect from the hydra
> change `semantic-object-handoff` (not on OR origin/development as of 2026-07-05; verified —
> `git grep x-openregister-handoff` returns nothing there, while `SemanticTypeResolver` is shipped).
> T01 and DC tasks can run now.

## Deduplication / Dependency Check

- [ ] **DC01**: Confirm the hydra `semantic-object-handoff` change (ADR-051) is published and OR ships the `x-openregister-handoff` dialect + `SemanticTypeResolver` in the deployed release; record the minimum OR version in `appinfo/info.xml`. Verify against OR HEAD, not assumptions.
- [ ] **DC02**: Bind the field mapping to the PUBLISHED contract names (title/summary/requester/channel/priority/provenance were the 2026-07-05 brief); adjust the mapping table in design/spec if the landed contract differs.
- [ ] **DC03**: Check `procest-delegation-via-events` status: reuse its listener conventions (fail-closed `class_exists` guard, registration in `lib/AppInfo/Application.php`) for any handoff post-create listener; do NOT add a second event-transport pattern.
- [ ] **DC04**: Coordinate with `brp-kvk-register-sets` on the requester/initiator fields — one write path: semantic reference is canonical, initiator display fields are a projection.

## Schema (MVP)

- [ ] **T01**: Declare `implements: ["https://openregister.app/ns#Case"]` on the `case` schema in `lib/Settings/procest_register.json`; validate the register JSON parses and re-imports idempotently.
- [ ] **T02**: Add the requester semantic-reference property and the provenance reference (back-link to the source pipelinq request) to the case schema per ADR-048; UUID-based relations only.
- [ ] **T03**: Extend the case schema's existing `x-openregister-notifications` declaration with the handoff-intake event (ADR-031 dialect; no legacy dialect, no imperative dispatch).

## Intake surface (MVP)

- [ ] **T04**: Surface handoff-created cases in `src/views/Werkvoorraad.vue` intake with a provenance affordance (origin app badge, source-request link, handoff timestamp); provenance details on the case detail view. Follow ADR-004 (modals in `src/modals/`, NcSelect inputLabel) where UI is touched.
- [ ] **T05**: Dutch + English i18n for provenance/intake strings (English source keys).

## Verification Tasks

- [ ] **V01**: With procest installed, OR's resolver lists procest's case schema as a `ns#Case` provider; with procest disabled on a scratch instance, pipelinq's handoff degrades gracefully (never disable openregister itself).
- [ ] **V02**: End-to-end through the UI: create a pipelinq request, hand it off, and see the case appear in procest's Werkvoorraad with correct title/description/intakeChannel/priority, requester resolving, and provenance link navigating back to the request.
- [ ] **V03**: The intake notification fires from the schema declaration (verify via OR notification pipeline) and `grep` confirms no imperative notification dispatch was added to procest `lib/`.
- [ ] **V04**: README "Pipelinq Bridge" claim is now demonstrably true; notify the `align-claims-and-licence` roadmap re-pointing for reversal (claim graduates from roadmap to shipped).
