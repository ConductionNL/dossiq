# Spec: procest-procurement-compliance

**Status:** proposed
**Scope:** procest
**Tier:** procurement-suite
**Depends on:** case-management, procest-procurement-tender-management, procest-procurement-contract-lifecycle, procest-procurement-supplier-management, procest-procurement-evaluation-award, openregister (lifecycle + aggregations + audit + notifications + retention per ADR-022), docudesk (UEA/EML PDF rendering)

## ADDED Requirements

### Requirement: REQ-PCC-001 — Drempelbedragen SHALL be a `ProcurementThreshold` register, not hardcoded enums

EU + nationale drempelbedragen (procurement thresholds) MUST be
seeded as a `ProcurementThreshold` register, not as constants in PHP.
This lets operators apply the European Commission's biannual revisions
without a code change (a fleet-wide lesson from ADR-031: rate-like
seed data belongs in registers).

Schema.org annotation: `schema:MonetaryAmountDistribution`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | e.g. `eu-werken`, `eu-leveringen`, `eu-diensten-klassiek`, `eu-diensten-speciale-sectoren`, `eu-concessies`, `nl-werken-sub`, `nl-leveringen-sub`, `nl-diensten-sub` |
| `amount` | number | Yes | Threshold in EUR (excl. BTW) |
| `regime` | enum | Yes | `klassiek`, `speciale-sectoren`, `concessies`, `nationaal` |
| `category` | enum | Yes | `werken`, `leveringen`, `diensten`, `sociale-en-andere-specifieke-diensten` |
| `effectiveFrom` | date | Yes | Start of period (typically `2026-01-01` / `2028-01-01`) |
| `effectiveTo` | date | No | End of period (null = current) |
| `sourceReference` | string | No | URL to the Commission Delegated Regulation or VNG/PIANOo announcement |

Seed source: `lib/Settings/seeds/procurement-thresholds-2026-2027.json`.

Statutory framing: Aw 2012 art. 2.1 + 3.4 (drempelbedragen); EU
Verordening 2019/1828 (and its biannual successors) sets the actual
values.

#### Scenario: A threshold is editable without a deploy

- **GIVEN** the European Commission publishes new thresholds for the
  2028-2029 period
- **WHEN** the operator adds a `ProcurementThreshold` record with
  `effectiveFrom: 2028-01-01`
- **THEN** new tender procedure recommendations MUST consult the new
  thresholds without a procest code change.

### Requirement: REQ-PCC-002 — Procedure-type recommendation SHALL be a declarative calculation on the Tender register

The `Tender` schema MUST declare an `x-openregister-calculations`
field `recommendedProcedureType` that, given `estimatedValue` +
`regime` + `category` and the matching `ProcurementThreshold` records,
returns the legally-mandated minimum procedure type (e.g.
`europees-openbaar`, `nationaal-meervoudig-onderhands`).

Procest MUST NOT author `ProcurementProcedureService::recommend()` —
per ADR-031 this is the calculation anti-pattern.

The operator MAY override; the override MUST be captured in audit
context with a justification field, and SHOULD trigger a notification
to the compliance officer.

#### Scenario: A €250k werken tender is recommended onderhands

- **GIVEN** the seed thresholds (`nl-werken-sub: 1.500.000` EUR
  drempel for nationaal regime)
- **WHEN** an operator creates a tender with `estimatedValue: 250000`,
  `regime: klassiek`, `category: werken`
- **THEN** `recommendedProcedureType` MUST resolve to
  `meervoudig-onderhands` (national sub-threshold per ARW 2016).

#### Scenario: An overridden recommendation notifies compliance

- **GIVEN** a €5M leveringen tender (EU regime mandated)
- **WHEN** the operator sets `procedureType: enkelvoudig-onderhands`
  with a justification
- **THEN** the save MUST succeed AND a notification MUST be
  dispatched to the `procurement-compliance-officer` group with the
  justification text.

### Requirement: REQ-PCC-003 — UEA SHALL be modelled as a `UeaDeclaration` register, not a PDF blob

The UEA SHALL be modelled as a structured `UeaDeclaration` register; the PDF rendering MUST be a docudesk artifact, not the canonical record.

The Uniform European Self-Declaration (UEA / ESPD, Annex 2 of EU
Verordening 2016/7) MUST be modelled as a structured `UeaDeclaration`
register. The PDF rendering for download/submission is a docudesk
artifact; the structured data is the canonical record.

Schema.org annotation: `schema:DigitalDocument` with the structured
fields treated as `schema:Dataset` payload.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `supplier` | string | Yes | FK to `Supplier` |
| `tender` | string | No | FK to `Tender` (NULL = standing declaration valid for multiple tenders within 6 months per UEA rules) |
| `partA` | object | Yes | Information concerning the procurement procedure and contracting authority |
| `partB` | object | Yes | Information about the economic operator |
| `partC` | object | Yes | Selection criteria (per Aw 2012 art. 2.86–2.94) |
| `partD` | object | Yes | Grounds for exclusion (uitsluitingsgronden) |
| `partE` | object | No | Information about subcontractors |
| `partF` | object | No | Reliance on capacities of other entities |
| `signedAt` | datetime | Yes | When the declaration was signed |
| `signedBy` | string | Yes | UID or external signature ref |
| `validUntil` | date | Yes | Auto-derived: `signedAt + 6 months` |
| `state` | enum | Yes | `draft`, `signed`, `submitted`, `verified`, `rejected`, `expired` |

#### Scenario: A UEA is reusable across multiple tenders within validity

- **GIVEN** a supplier signs a UEA with `tender: null`,
  `validUntil: 2026-12-01`
- **WHEN** the supplier participates in three different tenders before
  `2026-12-01`
- **THEN** all three tenders' EVA spec MUST be able to verify the
  same UEA record — without a new declaration per tender.

### Requirement: REQ-PCC-004 — EML-bestand (Eigen Verklaring) SHALL be modelled as a downloadable export from the UEA register

The EML-bestand SHALL be a declarative export derived from the `UeaDeclaration` register; procest MUST NOT author an export service.

For NL-domestic operators using the older `Eigen Verklaring`
mechanism (still accepted under Aw 2012 art. 2.86 for sub-threshold),
procest MUST expose an EML-bestand XML export derived from the
`UeaDeclaration` register. The export MUST be a declarative output
(OR's `x-openregister-export` or equivalent — a docudesk template
also acceptable), NOT a `EmlBestandExportService`.

#### Scenario: EML export carries the structured fields

- **GIVEN** a signed `UeaDeclaration`
- **WHEN** the operator triggers EML export
- **THEN** the resulting XML MUST contain the structured field
  payload; the procest call path MUST contain no XML-templating PHP.

### Requirement: REQ-PCC-005 — Compliance KPI dashboard SHALL be declarative widgets, not a `ComplianceReportService`

The compliance officer dashboard MUST be declared as
`x-openregister-widgets` blocks on the relevant registers covering:

- `tenders-on-or-above-eu-threshold` — count + ratio of tenders where
  `estimatedValue >= matched ProcurementThreshold.amount`,
  cross-referenced against actual `procedureType` (flags overrides).
- `contracts-exceeding-mantelovereenkomst-duration` — contracts
  beyond 4 years (Aw 2012 art. 2.140); flags require operator-supplied
  justification (lifecycle guard).
- `awards-without-publication` — `definitief-gegund` tenders missing
  a publication of the gunningsbericht within 30 days (Aw 2012 art.
  2.130).
- `suppliers-excluded` — count + reasons; per OR `audit-trail-immutable`
  the per-exclusion decision lineage is preserved.
- `maverick-spend` — `[future]` integration with financeq: contracts
  in effect without a procest source tender, where applicable.

Procest MUST NOT author `ComplianceReportService` —
per ADR-031 this is the aggregation + widget anti-pattern.

#### Scenario: An award without timely publication surfaces in the dashboard

- **GIVEN** a tender awarded 35 days ago without a publication ref
  set on the gunningsbesluit
- **WHEN** the compliance dashboard renders
- **THEN** the tender MUST appear in `awards-without-publication`
  with the days-overdue field calculated declaratively.

### Requirement: REQ-PCC-006 — Compliance notifications SHALL be declarative per ADR-031

The relevant schemas MUST declare `x-openregister-notifications`
covering:

- `procedure-override` — when an operator overrides
  `recommendedProcedureType`; recipients: compliance-officer group.
- `mantelovereenkomst-aging` — at year 3 of a `mantelovereenkomst`
  contract; recipients: contract owner + compliance-officer.
- `publication-missing` — at day 30 after `definitief-gegund` if no
  publication; recipients: tender inkoper + compliance-officer.
- `uea-expiring` — 30 days before `UeaDeclaration.validUntil`;
  recipients: supplier primaryContact + relevant tender inkopers.

Procest MUST NOT author `ComplianceNotificationService`.

#### Scenario: An overridden procedure recommendation fires a single notification

- **GIVEN** the override REQ-PCC-002 scenario
- **WHEN** the save commits
- **THEN** exactly one `procedure-override` notification MUST be
  dispatched (idempotency MUST prevent re-fire on subsequent edits
  to unrelated fields).

### Requirement: REQ-PCC-007 — Compliance pages SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Compliance` (`type: dashboard`)
  rendering the widgets declared in REQ-PCC-005, restricted to the
  `procurement-compliance-officer` and `procurement-admin` roles via
  the manifest's visibility predicate;
- a navigation entry `Procurement > UEA declarations` (`type: index`)
  binding to `UeaDeclaration`;
- a navigation entry `Procurement > Thresholds` (`type: index`)
  binding to `ProcurementThreshold` (admin-only).

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4. Per-role visibility is the manifest's
job — no per-page controller.

#### Scenario: The compliance dashboard is hidden for non-compliance roles

- **GIVEN** a user with role `procurement-officer` only
- **WHEN** they open the procest main menu
- **THEN** the `Compliance` entry MUST NOT appear.
