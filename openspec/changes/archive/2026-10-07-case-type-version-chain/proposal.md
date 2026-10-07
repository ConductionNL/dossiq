---
kind: config
depends_on: []
---

# Proposal: case-type-version-chain

Competitor gap register, row 2.3 "Case type versioning with draft, publish,
activate" (`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq. The register sizes it M; the re-read
of 2026-09-13 (`docs/research/competitor-gap-re-read-2026-09-13.md`, addendum
row 2.3) narrows it to S, because the chain is declared and the endpoint
exists.

## Why

The data is there and the page does not show it. `caseType` declares
`version`, `versionDate`, `previousVersion`, `supersededBy`, `validFrom`,
`validUntil` and `isDraft` (`lib/Settings/dossiq_register.json`).
`CaseDefinitionController::newVersion()` answers
`POST /api/case-definitions/{id}/new-version` (`appinfo/routes.php:132`) and
`openspec/specs/zaaktype-versioning/spec.md` REQ-ZV-02 requires a running case
to stay on its version. On `#CaseTypeDetail` the header actions are Export,
Import, Duplicate and Publish. There is no New version, no Deprecate, and the
Versions widget (`case-type-versions`) lists `workflowTemplate` rows, not the
case type's own predecessors and successors.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/admin/catalog/entities/case_type_version.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- Header action New version on `#CaseTypeDetail`, an `api-call` to the
  existing endpoint, that opens the created draft.
- Header action Deprecate that sets `validUntil` to today, visible on a
  published version with a successor.
- A Version chain panel on `#CaseTypeDetail`: an object-list over `caseType`
  filtered on the same `identifier`, sorted by `version` descending, showing
  version, draft or published, valid from, valid until.
- `#CaseTypes` shows the current version per identifier by default and a
  chip All versions.

## Ownership

dossiq builds all of it. It consumes OpenRegister's object-list widget and
the `api-call` action type from nextcloud-vue, both shipped.

## ADRs

- Company ADR-036 and ADR-049: built-in widgets and declared actions, no
  custom component.
- Company ADR-031: the chain is schema data, not a service.

## Capabilities

- Modified: `zaaktype-versioning`: the chain is visible and the two actions
  exist on the page.

## Impact

`src/manifest.json` pages `CaseTypeDetail` and `CaseTypes`;
`tests/vitest/caseTypeAuthoringManifest.spec.js`; one e2e spec. No PHP.
