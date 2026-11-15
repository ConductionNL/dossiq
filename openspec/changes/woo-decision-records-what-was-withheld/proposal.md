---
kind: code
depends_on: [woo-refusal-grounds-list, woo-request-takes-over-from-opencatalogi]
---

# Proposal: woo-decision-records-what-was-withheld

Woo capability programme, round 1, wave 3. Supporting change for row 6.16.

| row | text | our rating today |
| --- | --- | --- |
| 6.16 | The portal shows that a withheld record exists, and why it is withheld | partial (production) |

## Summary

When dossiq publishes a Woo decision, it tells opencatalogi which documents were withheld and on which refusal grounds, through `WithheldDocuments::record()`, and never sends a file, a hash or a document reference.

- Rows: supporting, supports 6.16 (its second half). `portaliq/publication-error-reports-and-withheld-notices` (https://github.com/ConductionNL/portaliq/issues/1221) closes the row.
- Wave 3.
- Dependencies: `opencatalogi/woo-decision-shows-what-was-withheld` (https://github.com/ConductionNL/opencatalogi/issues/1797), the receiving side; `dossiq/woo-refusal-grounds-list` (https://github.com/ConductionNL/dossiq/issues/3288), the codes opencatalogi resolves; `dossiq/woo-request-takes-over-from-opencatalogi` (https://github.com/ConductionNL/dossiq/issues/3289), whose `WooDecisionDrafts::wooDecision()` numbers the inventory.
- Decisions: D1 (dossiq decides and publishes the Woo decision), D3 (the grounds are dossiq's), D9 (showing the list is opt-in in opencatalogi, off by default) and D12 (no fallback without the other app).
- Build rules: openspec/woo-build-rules.md

## Why

opencatalogi's gap spec `woo-decision-shows-what-was-withheld` (opencatalogi spec PR #1752, REQ-WDW-002)
adds `OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record(string $publicationId, array $entries,
string $source): array` and says the dossiq side calls it after `WooPublicationService::publish()`.
No dossiq change makes that call. Today, read on dossiq `development` on 2026-10-06,
`WooPublicationService::publish()` builds the payload in `buildPayload()`, and
`selectDisclosableDocuments()` drops every `niet_openbaar` document, so a published Woo decision
carries no trace of what was withheld.

## What changes

1. After `publish()` has created or updated the publication, dossiq calls
   `record($publicationId, $entries, 'dossiq')` once, with one entry per `niet_openbaar` assessment
   of the case: `{position: int, grounds: list<string>}`. `position` is the document's inventory
   number as `WooDecisionDrafts::wooDecision()` numbers it, so the public list agrees with the
   decision's inventory. `grounds` are the assessment's `weigeringsgronden` codes.
2. No `title`. `wooDocumentAssessment` has no field that marks a title as public, and opencatalogi's
   `record()` stores no title on this path at all (REQ-WDW-002 as reconciled on 2026-10-06), so
   dossiq never sends one.
3. Nothing else. No file, file id, hash, document reference or text. One builder,
   `OCA\Dossiq\Woo\WithheldEntries::build(string $caseId): array`, produces the entries, and a test
   pins its keys.
4. `withdraw()` calls `record($publicationId, [], 'dossiq')`, which removes the stored list.
5. `publish()` answers the outcome under `withheldRecord`:
   `{status: 'recorded'|'not-recorded', recorded?: int, refused?: list<{position, code, reason}>, reason?: string}`,
   and the case page shows a refusal to the handler. A refused or failed record never rolls back
   the publication: a list not shown discloses less, which is the safe side.

## App absent

- opencatalogi not installed: `publish()` already refuses with `opencatalogi_not_installed`, so
  `record()` is never called and nothing is recorded.
- opencatalogi installed but older than `woo-decision-shows-what-was-withheld` (the class does not
  resolve): nothing is recorded, and `withheldRecord` answers `not-recorded` with reason
  `opencatalogi-too-old`. The publication stands.

## Cross-app contract

Method `OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record(string $publicationId, array
$entries, string $source): array`, entries exactly `{position: int, grounds: list<string>}` on both
sides (opencatalogi ignores and does not store any other key, `title` included),
answer `{recorded: int, refused: list<{position: int, code: string, reason: string}>}` with reasons
`unknown-ground`, `grounds-unavailable` and `no-publication`. opencatalogi tests its side in
`WithheldDocumentsRecordTest::testTheContractKeysMatchBothSides`; dossiq tests the call shape here.

## Wave and done

Wave 3, after opencatalogi's change is merged. Done means merged on `development` with CI green. Row
6.16 closes in portaliq, and reads `production` only once store releases carry every half.
