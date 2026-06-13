---
status: specified
status-note: "Synced 2026-06-14 from archived consolidation change add-procest-procurement-suite (kind:config). SPEC-COMPLETE; code chain pending (ADR-032). Depends on the openconnector TED/TenderNed source rows from add-openconnector-eu-procurement-sources."
---

# procest-procurement-publication-platform Specification

## Purpose
Define TED/OJEU + national notice publication for procest: notices as a
`PublicationNotice` register, eForms standard-form codes as a
`PublicationTemplate` register, declarative notice lifecycle, declarative
material-change (wezenlijke-wijziging) detection with re-publication
recommendation, publication via PSI slots — anchored in EU 2014/24 +
Verordening 2019/1780 (eForms) + Aw 2012.
## Requirements
### Requirement: REQ-PPP-001 — Publication notices SHALL be modelled as a `PublicationNotice` register, separate from `Tender`

A publication on TED, TenderNed, or a national platform MUST be a
distinct `PublicationNotice` record (Schema.org `schema:PublicationEvent`)
rather than a field on `Tender`. A single tender produces multiple
notices over its lifetime: vooraankondiging (PIN), aankondiging
(prior + actual), wijzigingsbericht (rectification), gunningsbericht,
opdrachtgevingsbericht — each is a separate notice subject to its
own publication state.

Schema.org annotation: `schema:PublicationEvent`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `tender` | string | Yes | FK to `Tender` UUID |
| `award` | string | No | FK to a `decision` of type `gunningsbesluit` (for award notices) |
| `noticeType` | enum | Yes | `vooraankondiging`, `aankondiging`, `rectificatie`, `gunningsbericht`, `concessieaankondiging`, `aankondiging-vrijwillige-transparantie`, `wijziging-opdracht` |
| `targetPlatform` | enum | Yes | `ted-ojeu`, `tenderned`, `mercell`, `negometrix`, `e-procurement-be`, `placsp-es`, `nationale-bekendmaking-overig` |
| `targetPlatformSlot` | string | Yes | PSI slot symbolic name (e.g. `tenderned-tenders`) |
| `eformsCode` | string | No | TED eForms standard form code (F01–F25 legacy, eForms 1..40 modern) |
| `payload` | object | Yes | The structured payload submitted (eForms XML or platform-native JSON) |
| `payloadDocumentRef` | string | No | docudesk URI of the human-readable rendering |
| `publishedAt` | datetime | No | Set on `confirmed` transition |
| `externalRef` | string | No | Platform's own ID (e.g. TED OJEU number, TenderNed publicatienummer) |
| `state` | enum | Yes | `draft`, `submitted`, `confirmed`, `rejected`, `superseded` |
| `supersededBy` | string | No | FK to a later notice that supersedes this one |

Statutory framing: EU Directive 2014/24/EU art. 49–55 (notice
publication); Verordening 2019/1780 (eForms); national bekendmakingen
per Aw 2012 art. 2.108 + 2.130.

#### Scenario: One tender carries multiple notices

- **GIVEN** a tender that progresses publication → rectification →
  award
- **WHEN** queried for its `PublicationNotice` records
- **THEN** three records MUST exist (`aankondiging`, `rectificatie`,
  `gunningsbericht`), all referencing the same tender UUID.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the procest codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `publication_`,
  `notice_`, `bekendmaking_`, or `aankondiging_`
- **THEN** no such classes SHALL exist; all notice data flows through
  the OR object API.

### Requirement: REQ-PPP-002 — TED eForms standard-form codes SHALL be a `PublicationTemplate` register, not hardcoded enums

TED eForms standard-form codes SHALL be seeded as a `PublicationTemplate` register; procest MUST NOT hardcode them as enums.

The set of TED eForms / legacy F01–F25 standard form codes — each
with field schema, mandatory-field rules, allowed CPV scope, allowed
procedure types — MUST be seeded in a `PublicationTemplate` register.
Each notice's `payload` MUST validate against its matched template.

Schema.org annotation: `schema:CreativeWorkSeries`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | Standard form code (`F02`, `eForm-12`, `nationale-bekendmaking`, ...) |
| `name` | string | Yes | Human-readable label |
| `applicableNoticeTypes` | array | Yes | Enum values from `PublicationNotice.noticeType` that this template covers |
| `targetPlatform` | enum | Yes | Same enum as the notice — couples template to platform |
| `payloadSchema` | object | Yes | JSON Schema for the `payload` field, derived from the canonical eForms XSD or platform spec |
| `effectiveFrom` | date | Yes | Required because eForms supersedes legacy F01–F25 from `2026-10-25` per EU regulation |
| `effectiveTo` | date | No | Null = current |
| `sourceUrl` | string | No | URL to the eForms regulation or platform spec |

#### Scenario: A submitted notice with payload not matching its template is rejected

- **GIVEN** a `PublicationNotice` with `targetPlatform: ted-ojeu`,
  `noticeType: aankondiging`, claiming `eformsCode: F02` but missing
  the mandatory `procurement-object` block
- **WHEN** the notice transitions `draft → submitted`
- **THEN** OR's schema validation MUST reject the payload citing the
  missing block.

### Requirement: REQ-PPP-003 — The `PublicationNotice` lifecycle SHALL be declarative per ADR-031

The `PublicationNotice` schema MUST declare an
`x-openregister-lifecycle` block:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `submitted` | operator action | `payload` MUST validate against matched `PublicationTemplate`; `targetPlatformSlot` MUST resolve to an active openconnector source |
| `submitted` | `confirmed` | inbound event from openconnector source | `externalRef` MUST be set from event payload |
| `submitted` | `rejected` | inbound event from openconnector source | rejection reason MUST be captured in audit context |
| `rejected` | `draft` | operator action | none |
| `confirmed` | `superseded` | operator action (when issuing a rectificatie or wijziging) | `supersededBy` MUST be set |

Per ADR-031, procest MUST NOT author `PublicationNoticeService::
transition*` methods.

#### Scenario: A confirmation event sets externalRef

- **GIVEN** a notice in `submitted` state
- **WHEN** the openconnector source delivers a `publication.confirmed`
  CloudEvent carrying the TED OJEU number
- **THEN** the lifecycle MUST transition to `confirmed` and
  `externalRef` + `publishedAt` MUST be set from the event payload.

### Requirement: REQ-PPP-004 — Material changes to a published tender SHALL be a declarative `wezenlijke-wijziging` calculation, surfacing a re-publication recommendation

Material changes SHALL be surfaced by a declarative calculation; procest MUST NOT author a material-change detector service.

When fields on a published `Tender` change (CPV codes, estimated
value moving past a threshold, procedure type, award criteria
weights), a declarative `x-openregister-calculations` field
`isMaterialChange` on `Tender` MUST surface `true`, and procest MUST
recommend publishing a `rectificatie` (or `wijziging-opdracht` after
award) notice.

Procest MUST NOT author a `MaterialChangeDetectorService` — per
ADR-031 this is the calculation anti-pattern. The threshold rules
(what counts as material) MUST be data — a `MaterialChangeRule`
register seed.

Statutory framing: Aw 2012 art. 2.163 (wezenlijke wijziging gegunde
overeenkomst); CJEU jurisprudence on material changes during
procedure (case C-454/06 *pressetext*).

#### Scenario: A CPV code change after publication recommends rectificatie

- **GIVEN** a published tender with `cpvCodes: ["72200000"]`
- **WHEN** an operator edits cpvCodes to `["72200000", "72300000"]`
- **THEN** `isMaterialChange` MUST resolve to `true`, AND a
  notification MUST recommend creating a `rectificatie` notice;
  the operator MAY override (with justification captured in audit).

#### Scenario: A whitespace edit on tender description is not material

- **GIVEN** the same published tender
- **WHEN** an operator fixes a typo in `description`
- **THEN** `isMaterialChange` MUST resolve to `false`; no recommendation
  fires.

### Requirement: REQ-PPP-005 — Publication SHALL flow through the resolved PSI slot, never a hand-rolled TED or TenderNed client

The `draft → submitted` transition MUST dispatch the payload via the
openconnector source resolved from `targetPlatformSlot`. Procest MUST
NOT author `TedSubmissionService`, `TenderNedClient`, or any
HTTP-bearing class for publication. Per ADR-019 this is the integration
registry anti-pattern.

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the procest codebase post-implementation
- **WHEN** scanned for `curl_init`, `GuzzleHttp\Client`, or hardcoded
  `ted.europa.eu`, `simap.europa.eu`, `tenderned.nl`, hostnames in
  `lib/` related to publication
- **THEN** no matches SHALL exist; the openconnector source is the
  only path.

#### Scenario: A publication notification dispatch reaches the right slot

- **GIVEN** a notice with `targetPlatformSlot: tenderned-tenders` in
  `draft`
- **WHEN** the operator triggers submit
- **THEN** an OR `ScheduledWorkflow` MUST be dispatched targeting the
  `tenderned-tenders` source with the payload; procest's code path
  MUST not invoke any HTTP client directly.

### Requirement: REQ-PPP-006 — Publication pages SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Publications` (`type: index`)
  binding to `PublicationNotice`;
- a `type: detail` page for individual notices showing the payload
  in human-readable form (rendered via docudesk template against
  `payloadDocumentRef`) + the lifecycle state + the external ref;
- a navigation entry `Procurement > Publication templates` (admin-
  only) binding to `PublicationTemplate`;
- a side-panel surface on tender detail pages showing all related
  publications for the tender, with quick-action buttons to draft
  the next applicable notice type.

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4.

#### Scenario: The publications index filters by platform

- **GIVEN** the manifest declares the publications page with a
  platform-filter facet
- **WHEN** an inkoper opens
  `/index.php/apps/procest/publications?targetPlatform=ted-ojeu`
- **THEN** the page MUST render via `CnIndexPage` showing only TED
  notices — no procest-side filter controller invoked.

