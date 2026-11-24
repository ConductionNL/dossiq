---
kind: code
depends_on: []
---

# Proposal: hydra-gates-green-on-development

## Why

`quality / Hydra Gates` is a required check on `development`, and the push run 38700178033 failed it, so no release can go out. Six gates reported a blocking finding. Five of them are this repo's to fix:

- gate-22 manifest-validation and gate-53 effective-manifest-crossref, both on the same single finding: `walkthrough.tours[0].startOn`.
- gate-43 table-headers: the review batch table in `DocumentReviewPanel.vue` has four column headers and no `scope=`, so a screen reader cannot associate a data cell with its header (WCAG 2.2 AA SC 1.3.1).
- gate-51 schema-property-titles: 26 properties in the two newest register fragments carry no `title`, and six carry no `description` either. `fieldsFromSchema` labels a field `prop.title || key`, so a case type manager saw `attendanceGraceHours` where a label belonged.
- gate-119 procedure-code: 186 procedure-named files against a baseline of 185. The file the count grew by is `lib/Service/Termijn/CaseNumberReader.php`.

The sixth, gate-23 or-abstraction-anti-patterns, is inherited and is not touched here.

## What changes

1. Every `<th>` in the review batch table declares `scope="col"`.
2. The `caseType.relations[]` and `caseType.sessions[]` properties carry an English `title` and `description`, and every new string gets an `en` and an `nl` catalogue entry, so the form does not render English inside a Dutch form.
3. `CaseNumberReader` moves from `OCA\Dossiq\Service\Termijn` to `OCA\Dossiq\Service`. The class is already generic: it answers a case's number for a case UUID. Only the directory named it after a procedure term, which is what gate-119 counted. Decision 182: procedures are configuration, code is generic and named by what it does.
4. gate-22 and gate-53 are NOT fixed here, because they cannot be. Both validate against the schema vendored in `ConductionNL/.github`, `hydra-gates/scripts/schemas/app-manifest-v2.schema.json`, which is at 2.89.0. `startOn` arrived in 2.94.0 and the published `@conduction/nextcloud-vue` ships 2.96.0, so this repo's manifest is valid against the library and refused by the gate. The repair belongs where the copy lives, and the gates repo says so in its own sync workflow. A pull request on `ConductionNL/.github` vendors the published 2.96.0 copy.

## Not changed

- `startOn` stays in `src/manifest.json`. Removing it is the documented rollback, and it would undo the 14 October decision that the tour starts only from the dashboard.
- The `lib/Service/Termijn/` directory keeps its name. Renaming it moves 14 more files and every `Termijn*` class that references them, which is decision 184's separate refactor programme.
- gate-23 or-abstraction-anti-patterns: `TenantAuditTrailService` and `BezwaarAuditTrail` write their own audit trail instead of OpenRegister's `AuditTrailMapper`. Inherited, on lines this change does not touch.

## Rollback

Each of the three code changes reverts on its own. Reverting the namespace move puts the gate-119 count back at 186.
