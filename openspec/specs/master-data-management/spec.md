# master-data-management Specification (procest as ADR-045 consumer)

**Scope:** procest (annotations only — engine and steward surface are OpenRegister's per ADR-045)
**Depends on:** OpenRegister >= 0.2.16 MDM engine (`x-openregister-quality` / `x-openregister-dedup` support, on-save score materialisation)

## Purpose

@e2e exclude Annotations-only capability: procest ships no MDM UI (the steward surface is OpenRegister's per ADR-045); the declared rules are proven by PHPUnit (tests/Unit/Settings/MdmAnnotationsTest.php); duplicate-candidate and merge behaviour executes inside OpenRegister and is e2e-tested there.

Procest declares data-quality and duplicate-detection rules on the schemas where duplicates matter
(case, supplier, partnerOrganization) so OpenRegister's generic MDM/governance surface governs
procest master data. Procest implements no dedup, merge, scoring, or steward UI of its own.

**OpenSpec changes**: [consume-or-mdm](../../changes/archive/2026-07-06-consume-or-mdm/) _(archived 2026-07-06)_

## Requirements

### Requirement: Duplicate-prone schemas MUST declare MDM annotations

Procest SHALL declare `x-openregister-quality` and `x-openregister-dedup` on the `case`, `supplier`,
and `partnerOrganization` schemas in `lib/Settings/procest_register.json`, with the rule sets fixed
in this change's design. Procest SHALL NOT declare `x-openregister-survivorship` while no
trust-tiered source-record schema exists.

#### Scenario: Register import carries the MDM rules

- **GIVEN** procest's register configuration is imported into OpenRegister
- **WHEN** the import completes
- **THEN** the `case`, `supplier`, and `partnerOrganization` schemas in OR MUST carry the declared quality and dedup annotations
- **AND** saving an object of any of these schemas MUST materialise `qualityScore` and `qualityStatus` on it

#### Scenario: DSO re-delivery surfaces as a duplicate candidate

- **GIVEN** a permit case exists with a `vergunningaanvraagRef`
- **WHEN** a second case is created with the same `vergunningaanvraagRef` and case type
- **THEN** OpenRegister's duplicate detection MUST produce a candidate pair for steward review
- **AND** procest MUST NOT block or merge the case itself

### Requirement: Procest MUST NOT implement app-local MDM machinery

Procest SHALL contain no duplicate-detection, merge, survivorship, quality-scoring, or
MDM-steward-view code; the governance surface is OpenRegister's (ADR-045). Procest SHALL add no
MDM pages or navigation entries to its manifest.

#### Scenario: Steward resolves duplicates in OpenRegister

- **GIVEN** duplicate supplier candidates exist for procest's register
- **WHEN** a steward opens OpenRegister's Duplicate Candidates view scoped to the procest register
- **THEN** the candidates MUST be reviewable and mergeable there
- **AND** after a reversible merge in OR, procest's views MUST show the surviving object without any procest-side code change

#### Scenario: No MDM surface ships in procest

- **WHEN** the procest app manifest and routes are inspected after this change
- **THEN** no MDM page, widget, or navigation entry MUST exist
- **AND** `lib/` MUST contain no service performing duplicate matching or merge for these schemas
