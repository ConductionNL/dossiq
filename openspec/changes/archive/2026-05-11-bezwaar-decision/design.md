# Design: bezwaar-decision

## Context

The beslissing op bezwaar is the legally binding final act of a bezwaarprocedure under Awb hoofdstuk 7. After ontvankelijkheidstoets, optional hoorzitting (Art. 7:2), and optional advies from a bezwaarschriftencommissie (Art. 7:13), the bestuursorgaan MUST issue a decision containing a full reconsideration (heroverweging, Art. 7:11), a motivation (Art. 7:12), an appeals notice, and — if applicable — a proceskostenvergoeding (Art. 7:15). This design records what the capability looks like in Procest.

## Entity: `bezwaarBesluit`

OpenRegister object linked to the bezwaar case. Schema.org: `schema:Action`. ZGW: `besluit` with `besluittype` "Beslissing op bezwaar". Required: `case`, `contestedDecision`, `disposition`, `motivering`, `decisionDate`, `effectiveDate`. Selected non-required properties drive the workflow:

| Property | Type | Role |
|----------|------|------|
| `disposition` | enum, 5 values | Awb 7:11 outcome (see matrix) |
| `motivering` | text | Awb Art. 7:12 motivation — always required |
| `heroverwegingExNunc` | text | Ex-nunc assessment at moment of beslissing |
| `advisoryReport` | UUID | Linked committee advies |
| `followsAdvice` | boolean | Whether decision follows committee advice |
| `deviationReason` | text | Required when `followsAdvice = false` (Art. 7:13 lid 7) |
| `replacementDecision` | UUID | New besluit; required for `gegrond_wijzigen` |
| `appealNotice` | object | Structured rechtsmiddelenclausule |
| `proceskosten` | object | Art. 7:15 cost award |
| `decisionMaker` | UUID | Bestuursorgaan or mandated official |
| `decisionDocument` | NC file ID | Generated PDF |
| `publishedAt` | datetime | Set on transition to published |
| `notifiedRecipients` | array | Audit of who was notified |

## Outcome matrix (Awb Art. 7:11)

| `disposition` | Replacement besluit? | Proceskosten possible? |
|---------------|----------------------|------------------------|
| `niet_ontvankelijk` | No | No |
| `ongegrond` | No | No (Art. 7:15 lid 2 — only when herroepen/gewijzigd) |
| `gegrond_handhaven` | No | No |
| `gegrond_herroepen` | Optional | Yes |
| `gegrond_wijzigen` | Required | Yes |

Mandatory fields per disposition enforced by REQ-BD-3.

## Appeal notice block (`appealNotice`)

Structured sub-object — NOT free-form prose — so the frontend renders consistently and audits can verify completeness:

```
appealNotice {
  competentCourt:       string   // "Rechtbank Midden-Nederland, sector bestuursrecht"
  beroepTerm:           string   // ISO 8601 duration; default "P6W" (Art. 6:7)
  effectiveDate:        date     // Date from which beroepTerm runs
  filingMethod:         enum     // digitaal | schriftelijk | beide
  filingUrl:            string   // required when filingMethod ∈ {digitaal, beide}
  filingAddress:        string   // required when filingMethod ∈ {schriftelijk, beide}
  griffierecht:         string
  voorlopigeVoorziening:boolean  // mentions option to request interim relief
}
```

REQ-BD-6 enforces presence; missing required sub-field → "Rechtsmiddelenclausule onvolledig", blocking publication.

## Proceskostenvergoeding rules (Awb Art. 7:15)

Awardable only when ALL hold: (1) `disposition` ∈ {`gegrond_herroepen`, `gegrond_wijzigen`}, (2) bezwaarmaker requested vergoeding before beslissing, (3) herroeping attributable to onrechtmatigheid van het primair besluit. `proceskosten` sub-object: `requested`, `awarded`, `pointBasis` (BPB-puntensysteem ref), `awardedPoints`, `pointValue` (EUR per point), `totalAmount` (computed), `reasoning`, `paymentDate`. When `requested = true` AND disposition ∈ herroepen/wijzigen, `awarded` MUST be explicitly set with reasoning (REQ-BD-7).

## Decision deadline (Awb Art. 7:10)

- 6 weeks from receipt of bezwaarschrift (lid 1)
- +6 weeks verdaging, once (lid 3)
- +4 weeks when hoorzitting held (lid 2)
- Plus opschorting periods (lid 4)

The `afhandelDeadline` lives in `bezwaar-lifecycle` (via `x-openregister-calculations`). REQ-BD-8 validates `decisionDate` against it; overshoot triggers "Beslistermijn overschreden" warning (precondition for Art. 4:17 dwangsom risk — out of scope here).

## Template-driven decision document generation

Decision letter is generated, not hand-typed, to guarantee:

- Appeals notice always present and complete
- Motivering, dispositif, replacement besluit appear in the legally required order
- House style and signature blocks consistent

`decisionDocument` is produced by merging the `bezwaarBesluit` into a configurable Word/PDF template. Default template ships with Procest; bestuursorganen may configure overrides under app settings. Generation is triggered on draft → published transition (REQ-BD-9).

## Publication + notification flow

On transition to `published` (REQ-BD-10):

1. Set `publishedAt` to current timestamp
2. Generate PDF; save to bezwaardossier as immutable
3. Notify bezwaarmaker, gemachtigde (if any), primair beslisser, advisory committee secretaris (if `advisoryReport` set)
4. Record recipients in `notifiedRecipients`
5. Transition bezwaar case status to "Beslissing op bezwaar"
6. Start beroep-clock from `effectiveDate`
7. Optionally file in MijnOverheid Berichtenbox when bezwaarmaker is natural person with BSN and integration configured

## Security & audit

- `decisionMaker` derived from bestuursorgaan mandate config — never from request body
- Once `publishedAt` set, bezwaarBesluit is immutable; corrections require a new besluit (rectificatie or intrekking)
- OpenRegister append-only audit trail satisfies Archiefwet for the full draft → published trail

## Why a GENERATE-style change?

A partial spec already exists (3 requirements) but is incomplete and was committed without a change record. Rather than authoring a greenfield change, this captures the FULL bezwaar-decision capability (10 requirements) as the canonical contract; on archive it replaces the partial spec. Tasks verify the existing schema/services and file follow-up issues for any gaps.
