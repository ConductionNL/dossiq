# Tasks — leaf-integrations

## 1. Create-from-email templates (REQ-LEAF-101)

- [ ] 1.1 `lib/Settings/procest_register.json`: add `configuration.mailObjectTemplate` to `case` (`title`/`description`/`intakeChannel`/`communicationChannel`/`startDate`/`initiatorDisplayName` per design D1) and `complaint` (`subject`/`description`/`receiptChannel`/`receiptDate`). Scalars only; never touch `initiatorSourceId`, `initiatorType`, `requester`, `complainant`. Bump the register version so the import repair step is not a no-op. `python3 -m json.tool` after each edit.
- [ ] 1.2 Assert every template key is a real property of its schema (cross-check against `properties`) and that no other Procest schema gains a `mailObjectTemplate`.

## 2. Talk on the case detail (REQ-LEAF-102)

- [ ] 2.1 `lib/Settings/procest_register.json`: add `"talk"` to `case.configuration.linkedTypes`.
- [ ] 2.2 `src/registry.js`: register `TalkLeafTab` (`kind: 'page'`, `component: leafTab('talk')`) following the `CalendarLeafTab` precedent, with an ADR-022 `_note`.
- [ ] 2.3 `src/manifest.json`: add the `case-talk` integration widget (`integrationId: "talk"`, icon `ChatOutline`), a layout entry, and the `TalkLeafTab` sidebar tab on the case detail page.

## 3. Forms citizen intake (REQ-LEAF-103)

- [ ] 3.1 `lib/Settings/procest_register.json`: add the optional `intakeFormRef` string property to `caseType` (title + description; additive — no required change).
- [ ] 3.2 New `lib/Service/FormsIntakeService.php`: resolve the bound caseType by form hash, create the case with initial status, `intakeChannel: "forms"`, `startDate` = submission date (statutory clock via the existing `deadline` calculation), submission answers into `description`, link the submission via the forms leaf. Full PHPDoc + SPDX headers.
- [ ] 3.3 Forms submission event listener registered in `lib/AppInfo/Application.php`; no-op when `forms` is disabled or the form is unbound. PHPUnit: bound-form creates a correctly-clocked case; unbound-form creates nothing (the guard must be shown able to say NO).

## 4. Maps on the VTH surfaces (REQ-LEAF-104)

- [ ] 4.1 `lib/Settings/register.d/40-mobiel-inspectie-offline.json`: add `configuration.linkedTypes: ["maps"]` to `fieldInspection` (the fragment has no `configuration` object today — create it; never drop an existing key).
- [ ] 4.2 `lib/Settings/procest_register.json`: extend `inspectionChecklistRun.configuration.linkedTypes` to `["forms", "photos", "maps"]`.
- [ ] 4.3 `src/manifest.json`: surface the maps leaf tab on the `fieldInspection` and `inspectionChecklistRun` detail pages. Touch nothing in OpenRegister/nextcloud-vue maps code and leave `CasesOnMapView` unchanged (owned by OpenRegister `integration-maps`).

## 5. Deck on the case detail (REQ-LEAF-105)

- [ ] 5.1 `lib/Settings/procest_register.json`: add `"deck"` to `case.configuration.linkedTypes`.
- [ ] 5.2 `src/registry.js` + `src/manifest.json`: `DeckLeafTab` (`leafTab('deck')`), `case-deck` widget (`integrationId: "deck"`, icon `ViewColumnOutline`), layout entry, sidebar tab — mirroring the Talk wiring.
- [ ] 5.3 Assert no code path links `task` records to Deck cards in either direction (grep `deck` across `lib/` — only declaration/UI wiring may match).

## 6. Specs, quality, verification

- [ ] 6.1 Sync the delta into `openspec/specs/leaf-integrations/spec.md` at archive time; ensure no `@spec` tag anywhere points at a change path (gate-46). `openspec validate` clean.
- [ ] 6.2 Grep gates, with a non-zero searched-file count asserted: `mailObjectTemplate` matches exactly the `case` + `complaint` declarations; `linkedTypes` additions are exactly `talk`, `deck`, `maps` (×2); every declared linkedType id exists in `nextcloud-vue/src/integrations/builtin/leaves.js` or is `decidesk-decisions`.
- [ ] 6.3 `php -l` on new/changed PHP; `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean on touched files; `npm run build` green; run the hydra-gates suite and resolve any finding.
- [ ] 6.4 Verify on a live instance: re-run the register import repair step, read `case`, `complaint`, `caseType`, `fieldInspection`, `inspectionChecklistRun` back **from OpenRegister** (not from the files — `case` is union-merged with `register.d/dso-omgevingsloket.json`) and assert the new `configuration` keys survived; `LogDanglingLinkedTypes` reports no dangling Procest value; Mail sidebar shows both create buttons and prefills per REQ-LEAF-101; case detail renders Talk and Deck surfaces (and their empty states with `spreed`/`deck` disabled); a bound Forms submission creates a correctly-clocked case and an unbound one creates nothing.
